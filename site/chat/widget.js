/* chat/widget.js — Burbuja del chat en toda la intranet (widget.php). Usa la misma API que el chat completo (chat/api.php):
   boot una vez, después sync por eventos (cada 4 s con el panel abierto, cada 15 s cerrado), history, send, read y typing.
   Cabezas: conversaciones fijadas (se guardan en este navegador) + las que tienen mensajes sin leer. */
(() => {
  'use strict';
  const API = '/toolz/chat/api.php';
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // La burbuja va al final de <body>: así ningún contenedor de la página la tapa (la barra de arriba incluida)
  for (const id of ['ccw-heads', 'ccw', 'ccw-panel']) { const el = $(id); if (el && el.parentNode !== document.body) document.body.appendChild(el); }
  const S = { me: 0, users: {}, convs: {}, last: 0, open: null, msgs: [], more: false, tab: 'recent', q: '', prefs: {}, booted: false, att: [], canPost: true, reads: [] };
  let pins = []; try { pins = JSON.parse(localStorage.getItem('ccw_pins') || '[]').map(Number).filter(Boolean); } catch (e) {}
  const savePins = () => { try { localStorage.setItem('ccw_pins', JSON.stringify(pins)); } catch (e) {} };
  // Avatares que no cargan (antes onerror en línea; CSP estricta): pasan a la inicial con su color, o se quitan.
  // El evento error no sube: se escucha en captura.
  document.addEventListener('error', e => {
    const t = e.target;
    if (!t || t.tagName !== 'IMG') return;
    if (t.hasAttribute('data-ccw-ini')) {
      const s = document.createElement('span');
      s.className = t.className; s.style.background = `hsl(${+t.getAttribute('data-ccw-hue') || 0} 70% 65%)`;
      s.textContent = t.getAttribute('data-ccw-ini');
      t.replaceWith(s);
    } else if (t.hasAttribute('data-ccw-rm')) t.remove();
  }, true);

  async function api(action, data = {}) {
    const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CH': '1' }, body: JSON.stringify({ action, ...data }) });
    const j = await r.json().catch(() => ({ ok: false, error: 'El servidor respondió algo inesperado' }));
    if (!r.ok || j.ok === false) throw new Error(j.error || 'No se pudo completar');
    return j;
  }

  // ── Piezas ─────────────────────────────────────────────────
  const userOf = id => S.users[id];
  function av(u, cls = 'ccw-av') {
    if (!u) return `<span class="${cls}" style="background:#3f3f46"></span>`;
    const ini = esc((u.name || '?').trim()[0].toUpperCase());
    if (u.avatar) return `<img class="${cls}" src="${esc(u.avatar)}" alt="" data-ccw-hue="${(u.id * 47) % 360}" data-ccw-ini="${ini}">`;
    return `<span class="${cls}" style="background:hsl(${(u.id * 47) % 360} 70% 65%)">${ini}</span>`;
  }
  function convName(c) {
    if (!c) return '';
    if (c.kind === 'dm') return userOf(c.peer)?.name || 'Conversación';
    if (c.kind === 'channel') return '#' + (c.name || 'canal');
    return c.name || c.members.filter(m => m !== S.me).map(m => userOf(m)?.name || '').slice(0, 3).join(', ') || 'Grupo';
  }
  function convAv(c, cls = 'ccw-av') {
    if (c.kind === 'dm') { const u = userOf(c.peer); return `<span style="position:relative;display:inline-flex">${av(u, cls)}${u?.online ? '<span class="ccw-dot"></span>' : ''}</span>`; }
    return `<span class="${cls} ch"><i class="fa-solid ${c.kind === 'channel' ? 'fa-hashtag' : 'fa-user-group'}"></i></span>`;
  }
  // Fechas en la zona de quien mira (assets/g7-time.js, ZONAS_HORARIAS.md); sin el helper, como antes
  const TZ = window.G7Time;
  const P = dt => (TZ && TZ.parse(dt)) || new Date(String(dt).replace(' ', 'T'));
  function when(dt) {
    if (!dt) return '';
    if (TZ) { const n = TZ.daysAgo(dt); return n === 0 ? TZ.time(dt) : n === 1 ? 'Ayer' : TZ.fmt(dt, { day: '2-digit', month: '2-digit' }); }
    const d = new Date(dt.replace(' ', 'T')), now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return 'Ayer';
    return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' });
  }
  const dayOf = dt => { if (TZ) return TZ.dayLabel(dt); const d = new Date(dt.replace(' ', 'T')), now = new Date(); if (d.toDateString() === now.toDateString()) return 'Hoy'; const y = new Date(now); y.setDate(now.getDate() - 1); return d.toDateString() === y.toDateString() ? 'Ayer' : d.toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' }); };
  // Formato básico del chat: links, @menciones, **negrita**, *cursiva*, `código`
  function fmt(s) {
    let h = esc(s);
    if (window.CCRefs) h = CCRefs.fmt(h);   // [[tipo:id]] → tarjetita (chat/refs.js)
    h = h.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    h = h.replace(/\[([^\]\n]{1,120})\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    h = h.replace(/(^|[\s(])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
    h = h.replace(/\*\*([^*\n]+)\*\*/g, '<b>$1</b>').replace(/(^|\s)\*([^*\n]+)\*/g, '$1<i>$2</i>');
    h = h.replace(/(^|\s)@([\w.\-]{2,40})/g, '$1<b style="color:var(--g7-accent,#ff6600)">@$2</b>');
    return h;
  }
  // En las vistas previas, un vínculo [[tarea:45]] se lee como "🔗 tarea"
  const plain = s => String(s || '').replace(/\[\[(cliente|contenido|tarea|ticket):\d+\]\]/g, '🔗 $1').replace(/\[\[jira:([A-Z0-9_]+-\d+)\]\]/g, '🔗 $1');
  const kb = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';

  // ── Contador y cabezas ─────────────────────────────────────
  function totalUnread() { return Object.values(S.convs).reduce((n, c) => n + (c.muted ? 0 : c.unread), 0); }
  function drawBadge() {
    const n = totalUnread(), b = $('ccw-n');
    b.hidden = !n; b.textContent = n > 99 ? '99+' : n;
    $('ccw').setAttribute('aria-label', n ? `Abrir el chat, ${n} sin leer` : 'Abrir el chat');
  }
  function drawHeads() {
    const box = $('ccw-heads');
    const ids = [...pins.filter(id => S.convs[id])];
    for (const c of Object.values(S.convs).sort((a, b) => (b.last_msg_at || '').localeCompare(a.last_msg_at || '')))
      if (c.unread && !c.muted && c.kind !== 'channel' && !ids.includes(c.id)) ids.push(c.id);
    const show = ids.filter(id => id !== S.open || $('ccw-panel').hidden).slice(0, 5);
    box.innerHTML = show.map(id => {
      const c = S.convs[id], u = c.kind === 'dm' ? userOf(c.peer) : null;
      const face = u ? (u.avatar ? `<img src="${esc(u.avatar)}" alt="" data-ccw-rm>` : `<span class="ini" style="background:hsl(${(u.id * 47) % 360} 70% 65%)">${esc((u.name || '?')[0].toUpperCase())}</span>`)
        : `<span class="ini" style="background:var(--g7-bg-5,#2a2a2a);color:var(--g7-text,#fff)"><i class="fa-solid ${c.kind === 'channel' ? 'fa-hashtag' : 'fa-user-group'}"></i></span>`;
      return `<div class="ccw-head" role="button" tabindex="0" data-head="${id}" aria-label="${esc(convName(c))}${c.unread ? ', ' + c.unread + ' sin leer' : ''}">${face}
        ${u?.online ? '<span class="on"></span>' : ''}${c.unread ? `<span class="n">${c.unread > 99 ? '99+' : c.unread}</span>` : ''}
        ${pins.includes(id) ? `<button type="button" class="x" data-unpin="${id}" aria-label="Quitar burbuja" title="Quitar burbuja"><i class="fa-solid fa-xmark"></i></button>` : ''}<span class="tip">${esc(convName(c))}</span></div>`;
    }).join('');
  }

  // ── Lista ──────────────────────────────────────────────────
  function drawList() {
    const box = $('ccw-items'), q = S.q.toLowerCase().trim();
    if (S.tab === 'people') {
      const list = Object.values(S.users).filter(u => u.id !== S.me && u.active && (!q || (u.name + ' ' + u.username).toLowerCase().includes(q)))
        .sort((a, b) => (b.online - a.online) || a.name.localeCompare(b.name, 'es'));
      box.innerHTML = list.map(u => `<button type="button" class="ccw-it" data-user="${u.id}"><span style="position:relative;display:inline-flex">${av(u)}${u.online ? '<span class="ccw-dot"></span>' : ''}</span>
        <span class="t"><b>${esc(u.name)}</b><small>${esc(u.status_text || u.position || u.dept || '')}</small></span></button>`).join('') || '<div class="ccw-empty">Nadie con ese nombre.</div>';
      return;
    }
    const list = Object.values(S.convs).filter(c => !c.archived && (c.last_msg_id || c.kind !== 'dm') && (!q || convName(c).toLowerCase().includes(q)))
      .sort((a, b) => (b.unread > 0) - (a.unread > 0) || (b.last_msg_at || '').localeCompare(a.last_msg_at || ''));
    box.innerHTML = list.map(c => {
      const who = c.last && c.last.user_id === S.me ? 'Tú: ' : c.last && c.kind !== 'dm' ? (userOf(c.last.user_id)?.name?.split(' ')[0] || '') + ': ' : '';
      return `<button type="button" class="ccw-it${c.unread && !c.muted ? ' unread' : ''}" data-conv="${c.id}">${convAv(c)}
        <span class="t"><b>${esc(convName(c))}</b><small>${c.last ? esc(who + plain(c.last.body || (c.last.kind === 'file' ? 'Archivo' : ''))) : 'Sin mensajes todavía'}</small></span>
        <span class="r">${when(c.last_msg_at)}${c.unread && !c.muted ? `<span class="n">${c.unread > 99 ? '99+' : c.unread}</span>` : c.muted ? '<i class="fa-solid fa-bell-slash"></i>' : ''}</span></button>`;
    }).join('') || `<div class="ccw-empty">${q ? 'No hay conversaciones con ese nombre.' : 'Todavía no tienes conversaciones. Busca a alguien en Personas.'}</div>`;
  }

  // ── Conversación ───────────────────────────────────────────
  function msgHtml(m, prev) {
    const mine = m.user_id === S.me, u = userOf(m.user_id), c = S.convs[S.open];
    const cont = prev && prev.user_id === m.user_id && !prev.deleted && (P(m.created_at) - P(prev.created_at)) < 5 * 60000 && dayOf(prev.created_at) === dayOf(m.created_at);
    let body = '';
    if (m.deleted) body = '<span class="del">Mensaje eliminado</span>';
    else {
      if (m.reply_to && !m.reply_to.deleted) body += `<span class="rq">${esc(userOf(m.reply_to.user_id)?.name || '')}: ${esc((m.reply_to.body || '').slice(0, 90))}</span>`;
      if (m.body) body += fmt(m.body);
      for (const f of m.files || []) {
        const url = '/toolz/chat/' + f.url;
        body += /^image\//.test(f.mime) ? `<a href="${url}" target="_blank" rel="noopener"><img class="pic" src="${url}" alt="${esc(f.name)}" loading="lazy"></a>`
          : /^audio\//.test(f.mime) ? `<audio class="aud" data-fk="${+f.id}" src="${url}" controls preload="metadata"></audio>`
          : /^video\//.test(f.mime) ? `<video class="pic" data-fk="${+f.id}" src="${url}" controls preload="metadata"></video>`
          : `<a class="file" href="${url}" target="_blank" rel="noopener"><i class="fa-solid fa-file"></i><span>${esc(f.name)} · ${kb(f.size)}</span></a>`;
      }
      if (m.poll) body += `<span class="th"><i class="fa-solid fa-square-poll-vertical"></i> Encuesta: abrila en el chat completo</span>`;
      if (m.thread_count) body += `<a class="th" href="/toolz/chat/?m=${m.id}"><i class="fa-regular fa-comments"></i> ${m.thread_count} respuesta${m.thread_count > 1 ? 's' : ''} en el hilo</a>`;
    }
    const day = !prev || dayOf(prev.created_at) !== dayOf(m.created_at) ? `<div class="ccw-day">${esc(dayOf(m.created_at))}</div>` : '';
    return `${day}<div class="ccw-m${mine ? ' me' : ''}${cont && !day ? ' cont' : ''}${m.pending ? ' pend' : ''}${m.failed ? ' err' : ''}" data-mid="${m.id || ''}">${mine ? '' : av(u)}
      <div class="ccw-b">${!mine && c && c.kind !== 'dm' && !(cont && !day) ? `<span class="who">${esc(u?.name || '')}</span>` : ''}${body}<span class="tm">${m.failed ? 'No se envió · toca para reintentar' : m.pending ? 'Enviando…' : (TZ ? TZ.time(m.created_at) : P(m.created_at).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })) + (m.edited ? ' · editado' : '')}${mine && !m.pending && seenBy(m) ? ' · Visto' : ''}</span></div></div>`;
  }
  function seenBy(m) { const c = S.convs[S.open]; return c && c.kind === 'dm' && S.reads.some(r => r.user_id !== S.me && r.last_read_id >= m.id); }
  function drawMsgs(keepBottom = true) {
    const box = $('ccw-msgs');
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    const prevH = box.scrollHeight;
    // Los audios y videos se conservan: si no, cada mensaje o "visto" que llega reinicia la reproducción
    const media = new Map(); box.querySelectorAll('[data-fk]').forEach(el => media.set(el.dataset.fk, el));
    box.innerHTML = (S.more ? '<button type="button" class="ccw-more" id="ccw-older">Ver mensajes anteriores</button>' : '')
      + (S.msgs.length ? S.msgs.map((m, i) => msgHtml(m, S.msgs[i - 1])).join('') : '<div class="ccw-empty">Todavía no hay mensajes. ¡Saluda!</div>');
    if (media.size) box.querySelectorAll('[data-fk]').forEach(el => { const o = media.get(el.dataset.fk); if (o && o.getAttribute('src') === el.getAttribute('src')) el.replaceWith(o); });
    if (keepBottom === 'top') box.scrollTop = box.scrollHeight - prevH;
    else if (keepBottom || atBottom) box.scrollTop = box.scrollHeight;
    if (window.CCRefs) CCRefs.hydrate(box, api);
  }
  async function openConv(id) {
    const c = S.convs[id]; if (!c) return;
    S.open = id; S.msgs = []; S.more = false; S.att = []; drawAtt();
    showPanel(true);
    $('ccw-list').hidden = true; $('ccw-conv').hidden = false; $('ccw-back').hidden = false;
    $('ccw-title').textContent = convName(c);
    $('ccw-hav').innerHTML = convAv(c);
    const u = c.kind === 'dm' ? userOf(c.peer) : null;
    $('ccw-sub').textContent = u ? (u.online ? 'En línea' : (u.status_text || u.position || '')) : (c.kind === 'channel' ? (c.topic || 'Canal') : c.members.length + ' personas');
    // Con una persona que eligió su zona: su hora local (se actualiza sola) y la diferencia con la mía
    const loc = u && u.id !== S.me && u.loc && u.loc.tz && TZ ? u.loc : null;
    if (loc) $('ccw-sub').insertAdjacentHTML('beforeend', `<span class="ccw-tz" title="${esc(TZ.countryName(loc.cc) + ' · ' + loc.zone + ' · ' + TZ.diffLabel(loc.tz))}">${$('ccw-sub').textContent ? ' · ' : ''}${TZ.flag(loc.cc)} <span data-g7-clock="${esc(loc.tz)}">${TZ.clock(loc.tz)}</span></span>`);
    // Conversación con una persona: si tiene el cronómetro de una tarea corriendo, "Trabajando en T-0001 · …" (trabajo/user_work_lib.php)
    if (u && window.CCRefs) { const open0 = id; api('user_work', { user: u.id }).then(d => { const l = CCRefs.workLine(d.work); if (l && S.open === open0) $('ccw-sub').innerHTML = l; }).catch(() => {}); }
    $('ccw-pin').hidden = false; $('ccw-pin').classList.toggle('on', pins.includes(id));
    $('ccw-pin').title = pins.includes(id) ? 'Quitar burbuja' : 'Fijar como burbuja';
    $('ccw-full').href = '/toolz/chat/?c=' + id;
    $('ccw-msgs').innerHTML = '<div class="ccw-empty">Cargando…</div>';
    drawHeads();
    try {
      const r = await api('history', { conv: id, limit: 40 });
      if (S.open !== id) return;
      S.msgs = r.messages; S.more = r.more_before; S.reads = r.reads || []; S.canPost = r.can_post;
      $('ccw-comp').hidden = !r.can_post;
      $('ccw-ro').hidden = r.can_post;
      $('ccw-ro').innerHTML = r.can_join ? 'No eres miembro de este canal.<button type="button" id="ccw-join">Unirme</button>' : 'En esta conversación no puedes escribir.';
      drawMsgs(true); markRead();
      if (r.can_post) setTimeout(() => $('ccw-ta').focus(), 30);
    } catch (e) { $('ccw-msgs').innerHTML = `<div class="ccw-empty">${esc(e.message)}</div>`; }
  }
  function markRead() {
    const c = S.convs[S.open]; if (!c || document.hidden || $('ccw-panel').hidden) return;
    const last = S.msgs.filter(m => m.id && !m.pending).pop(); if (!last) return;
    if (c.unread || c.last_read_id < last.id) { c.unread = 0; c.last_read_id = last.id; drawBadge(); drawHeads(); api('read', { conv: c.id, last_id: last.id }).catch(() => {}); }
  }
  function backToList() {
    S.open = null; $('ccw-conv').hidden = true; $('ccw-list').hidden = false; $('ccw-back').hidden = true; $('ccw-pin').hidden = true;
    $('ccw-title').textContent = 'Chat'; $('ccw-sub').textContent = ''; $('ccw-hav').innerHTML = ''; $('ccw-full').href = '/toolz/chat/';
    drawList(); drawHeads();
  }
  function showPanel(on) {
    $('ccw-panel').hidden = !on; $('ccw').classList.toggle('open', on); $('ccw').setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on && !S.open) { drawList(); setTimeout(() => $('ccw-q').focus(), 30); }
    drawHeads(); schedule();
  }

  // ── Enviar ─────────────────────────────────────────────────
  const rid = () => 'w' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
  async function send(text, retry = null) {
    const conv = S.open; if (!conv) return;
    const files = S.att.filter(a => a.file).map(a => a.file);
    if (S.att.some(a => !a.file && !a.err)) { flash('Espera a que terminen de subir los archivos'); return; }
    if (!text.trim() && !files.length && !retry) return;
    const m = retry || { id: 0, client_id: rid(), user_id: S.me, conv_id: conv, body: text.trim(), files, created_at: TZ ? TZ.toStore(new Date()) : new Date().toISOString().slice(0, 19).replace('T', ' '), pending: true };
    m.pending = true; m.failed = false;
    if (!retry) { S.msgs.push(m); S.att = []; drawAtt(); }
    drawMsgs(true);
    try {
      const r = await api('send', { conv, body: m.body, client_id: m.client_id, files: (m.files || []).map(f => f.id) });
      beep('sent');
      if (S.open !== conv) return;
      const i = S.msgs.indexOf(m);
      if (S.msgs.some(x => x.id === r.message.id)) S.msgs.splice(i, 1); else S.msgs[i] = r.message;
      drawMsgs(true);
    } catch (e) { m.pending = false; m.failed = true; drawMsgs(); flash(e.message); }
  }
  function flash(msg) { $('ccw-typing').textContent = msg; $('ccw-typing').style.color = '#f87171'; setTimeout(() => { $('ccw-typing').style.color = ''; $('ccw-typing').textContent = ''; }, 3500); }
  // Adjuntos: subida en partes de 1,5 MB (el hosting corta en 2 MB)
  function drawAtt() {
    $('ccw-att').innerHTML = S.att.map((a, i) => `<span class="${a.err ? 'err' : ''}"><i class="fa-solid ${a.err ? 'fa-triangle-exclamation' : a.file ? 'fa-paperclip' : 'fa-spinner fa-spin'}"></i>${esc(a.name)}${a.err ? ' · ' + esc(a.err) : a.file ? '' : ' · ' + a.pct + '%'}<button type="button" data-rmatt="${i}" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></span>`).join('');
  }
  async function upload(f) {
    const conv = S.open; if (!conv) return;
    const a = { name: f.name, pct: 0, file: null, err: '' }; S.att.push(a); drawAtt();
    const CH = 1.5 * 1024 * 1024, parts = Math.max(1, Math.ceil(f.size / CH));
    const token = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');
    try {
      for (let i = 0; i < parts; i++) {
        const fd = new FormData(); fd.append('action', 'up_chunk'); fd.append('token', token); fd.append('index', i); fd.append('chunk', f.slice(i * CH, (i + 1) * CH), 'p');
        const r = await fetch(API, { method: 'POST', headers: { 'X-CH': '1' }, body: fd });
        const j = await r.json().catch(() => ({ ok: false, error: 'El servidor rechazó el archivo' }));
        if (!r.ok || j.ok === false) throw new Error(j.error || 'No se pudo subir');
        a.pct = Math.round((i + 1) / parts * 100); drawAtt();
      }
      const d = await api('up_done', { token, parts, size: f.size, name: f.name, conv });
      a.file = d.file; drawAtt();
    } catch (e) { a.err = e.message; drawAtt(); api('up_abort', { token }).catch(() => {}); }
  }

  // ── Sincronización ─────────────────────────────────────────
  let timer = null, busy = false, failN = 0, presAt = 0;
  // Con el servidor ocupado (503) o sin conexión, espera cada vez más (hasta 1 minuto) en vez de insistir; al responder, vuelve al ritmo normal
  function schedule() {
    clearTimeout(timer);
    const base = document.hidden ? 60000 : $('ccw-panel').hidden ? 15000 : 4000;
    timer = setTimeout(sync, failN ? Math.min(60000, base * 2 ** failN) + Math.random() * 2000 : base);
  }
  let audio = null;
  function beep(kind = 'chat') {
    if (S.prefs.sound === false) return;
    if (window.G7Juice && G7Juice.alert) { G7Juice.alert(kind); return; }   // sonidos de g7-juice.js
    if (kind === 'sent') return;
    try { audio = audio || new (window.AudioContext || window.webkitAudioContext)(); const o = audio.createOscillator(), g = audio.createGain(); o.frequency.value = 880; g.gain.setValueAtTime(.06, audio.currentTime); g.gain.exponentialRampToValueAtTime(.0001, audio.currentTime + .25); o.connect(g); g.connect(audio.destination); o.start(); o.stop(audio.currentTime + .26); } catch (e) {}
  }
  async function sync() {
    if (busy || !S.booted) return schedule();
    busy = true;
    try {
      // Quién está en línea: con el panel abierto, cada 20 s (el servidor lo actualiza cada 30 s); los mensajes llegan en cada consulta
      const presence = !$('ccw-panel').hidden && Date.now() - presAt > 20000;
      const r = await api('sync', { after: S.last, open: S.open || 0, presence });
      failN = 0; if (presence) presAt = Date.now();
      S.last = r.last_event;
      for (const c of r.convs || []) S.convs[c.id] = c;
      for (const g of r.gone || []) { delete S.convs[g]; if (S.open === g) backToList(); }
      for (const p of r.presence || []) if (S.users[p.id]) Object.assign(S.users[p.id], p);
      let ding = false;
      for (const m of r.messages || []) {
        if (m.parent_id) continue;
        if (m.conv_id === S.open) {
          const i = S.msgs.findIndex(x => x.id === m.id || (m.client_id && x.client_id === m.client_id));
          if (i >= 0) S.msgs[i] = m; else S.msgs.push(m);
        }
        const c = S.convs[m.conv_id];
        if (m.user_id !== S.me && !m.deleted && c && !c.muted && (m.conv_id !== S.open || $('ccw-panel').hidden || document.hidden) && new Date() - P(m.created_at) < 120000) ding = c.kind === 'dm' ? 'dm' : (ding || 'chat');
      }
      if (r.reads) S.reads = r.reads;
      if (S.open && !$('ccw-panel').hidden) {
        if ((r.messages || []).some(m => m.conv_id === S.open) || r.reads) drawMsgs(false);
        const t = (r.typing || []).map(id => userOf(id)?.name?.split(' ')[0]).filter(Boolean);
        if (!$('ccw-typing').style.color) $('ccw-typing').textContent = t.length ? t.join(', ') + (t.length > 1 ? ' están escribiendo…' : ' está escribiendo…') : '';
        markRead();
      }
      if (ding) beep(ding);
      drawBadge(); drawHeads();
      if (!$('ccw-panel').hidden && !S.open) drawList();
      if (r.more) { busy = false; return sync(); }
    } catch (e) { if (/sesión|401/i.test(e.message)) { busy = false; return; } failN = Math.min(failN + 1, 4); }
    busy = false; schedule();
  }
  async function boot() {
    try {
      const r = await api('boot');
      S.me = r.me.id; S.prefs = r.prefs || {}; S.last = r.last_event;
      for (const u of r.users) S.users[u.id] = u;
      for (const c of r.convs) S.convs[c.id] = c;
      pins = pins.filter(id => S.convs[id]); savePins();
      S.booted = true;
      drawBadge(); drawHeads();
    } catch (e) { /* sin acceso al chat: la burbuja queda como un link */ $('ccw').addEventListener('click', () => { location.href = '/toolz/chat/'; }); return; }
    schedule();
  }

  // ── Eventos ────────────────────────────────────────────────
  $('ccw').addEventListener('click', () => { if (!S.booted) return; showPanel($('ccw-panel').hidden); if (!$('ccw-panel').hidden && S.open) { drawMsgs(true); markRead(); } });
  $('ccw-close').addEventListener('click', () => showPanel(false));
  $('ccw-back').addEventListener('click', backToList);
  $('ccw-pin').addEventListener('click', () => {
    const id = S.open; if (!id) return;
    pins = pins.includes(id) ? pins.filter(x => x !== id) : [...pins.filter(x => x !== id), id].slice(-5);
    savePins(); $('ccw-pin').classList.toggle('on', pins.includes(id)); $('ccw-pin').title = pins.includes(id) ? 'Quitar burbuja' : 'Fijar como burbuja'; drawHeads();
  });
  document.querySelector('#ccw-panel .ccw-tabs').addEventListener('click', e => {
    const b = e.target.closest('[data-tab]'); if (!b) return;
    S.tab = b.dataset.tab; document.querySelectorAll('#ccw-panel .ccw-tabs button').forEach(x => x.classList.toggle('on', x === b)); drawList();
  });
  $('ccw-q').addEventListener('input', e => { S.q = e.target.value; drawList(); });
  $('ccw-items').addEventListener('click', async e => {
    const c = e.target.closest('[data-conv]'), u = e.target.closest('[data-user]');
    if (c) openConv(+c.dataset.conv);
    else if (u) { try { const r = await api('dm_open', { user_id: +u.dataset.user }); S.convs[r.conv.id] = r.conv; openConv(r.conv.id); } catch (err) { flash(err.message); } }
  });
  $('ccw-heads').addEventListener('click', e => {
    const x = e.target.closest('[data-unpin]');
    if (x) { e.stopPropagation(); pins = pins.filter(id => id !== +x.dataset.unpin); savePins(); drawHeads(); return; }
    const h = e.target.closest('[data-head]'); if (h) openConv(+h.dataset.head);
  });
  $('ccw-heads').addEventListener('keydown', e => { const h = e.target.closest('[data-head]'); if (h && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openConv(+h.dataset.head); } });
  $('ccw-msgs').addEventListener('click', async e => {
    if (e.target.closest('#ccw-older')) {
      const first = S.msgs.find(m => m.id); if (!first) return;
      try { const r = await api('history', { conv: S.open, before: first.id, limit: 40 }); S.msgs = [...r.messages, ...S.msgs]; S.more = r.more_before; drawMsgs('top'); } catch (err) { flash(err.message); }
      return;
    }
    const f = e.target.closest('.ccw-m.err'); if (f) { const m = S.msgs.find(x => String(x.id || '') === f.dataset.mid && x.failed) || S.msgs.find(x => x.failed); if (m) send('', m); }
  });
  $('ccw-ro').addEventListener('click', async e => {
    if (!e.target.closest('#ccw-join')) return;
    try { await api('join', { conv: S.open }); openConv(S.open); } catch (err) { flash(err.message); }
  });
  const ta = $('ccw-ta');
  let typT = 0;
  ta.addEventListener('input', () => {
    ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
    if (S.open && Date.now() - typT > 3000) { typT = Date.now(); api('typing', { conv: S.open }).catch(() => {}); }
  });
  ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('ccw-comp').requestSubmit(); } });
  $('ccw-comp').addEventListener('submit', e => { e.preventDefault(); const t = ta.value; ta.value = ''; ta.style.height = ''; send(t); });
  $('ccw-file-btn').addEventListener('click', () => $('ccw-file').click());
  // Emojis y vínculos (chat/refs.js)
  $('ccw-emoji')?.addEventListener('click', e => window.CCRefs && CCRefs.emoji(e.currentTarget, em => CCRefs.insert(ta, em)));
  $('ccw-link')?.addEventListener('click', e => window.CCRefs && CCRefs.picker(e.currentTarget, api, tok => CCRefs.insert(ta, tok)));
  // @ en la burbuja: personas, clientes y tickets. Una persona queda como @usuario; un cliente o ticket, como vínculo [[tipo:id]]
  let mBox = null, mSt = null, mT = null; const MREF = {};
  const mClose = () => { if (mBox) { mBox.remove(); mBox = null; } mSt = null; };
  function mDraw() {
    if (!mSt || !mSt.opts.length) return mClose();
    if (!mBox) { mBox = document.createElement('div'); mBox.className = 'cc-pk ccw-mention'; mBox.setAttribute('role', 'listbox'); document.body.appendChild(mBox);
      mBox.addEventListener('mousedown', e => { const b = e.target.closest('[data-mi]'); if (b) { e.preventDefault(); mPick(+b.dataset.mi); } }); }
    mBox.innerHTML = `<div class="cc-pk-list">${mSt.opts.map((o, i) => `<button type="button" class="cc-pk-it${i === mSt.i ? ' on' : ''}" data-mi="${i}" role="option" aria-selected="${i === mSt.i}" style="--rc:${esc(o.color || '#888')}">${o.ref ? `<i class="fa-solid ${esc(o.icon)}"></i>` : av(userOf(o.id))}<span><b>${esc(o.name)}</b><small>${esc(o.sub)}</small></span></button>`).join('')}</div>`;
    const r = $('ccw-comp').getBoundingClientRect();
    mBox.style.left = r.left + 'px'; mBox.style.width = Math.min(360, r.width) + 'px'; mBox.style.top = Math.max(8, r.top - mBox.offsetHeight - 6) + 'px';
  }
  function mCheck() {
    const before = ta.value.slice(0, ta.selectionStart), m = before.match(/(^|\s)@([\p{L}\p{N}_.\-]*)$/u);
    if (!m || !S.open) return mClose();
    const q = m[2].toLowerCase(), c = S.convs[S.open];
    const ppl = (c?.members || Object.keys(S.users).map(Number)).filter(id => id !== S.me).map(userOf).filter(u => u && (u.name.toLowerCase().includes(q) || (u.username || '').toLowerCase().includes(q))).slice(0, 5)
      .map(u => ({ id: u.id, name: u.name, word: u.username, sub: u.position || '@' + u.username }));
    const refs = (MREF[q] || []).map(r => ({ ref: r.kind + ':' + r.id, name: r.title, sub: r.label + (r.code ? ' · ' + r.code : ''), icon: r.icon, color: r.color }));
    if (!(q in MREF)) { clearTimeout(mT); mT = setTimeout(() => api('ref_search', { q, only: ['cliente', 'ticket', 'jira'] }).then(d => { MREF[q] = (d.items || []).slice(0, 5); if (document.activeElement === ta) mCheck(); }).catch(() => { MREF[q] = []; }), 180); }
    mSt = { start: before.length - m[2].length - 1, opts: ppl.concat(refs), i: Math.min(mSt ? mSt.i : 0, Math.max(0, ppl.length + refs.length - 1)) };
    mDraw();
  }
  function mPick(i) {
    const o = mSt && mSt.opts[i]; if (!o) return;
    const ins = o.ref ? '[[' + o.ref + ']] ' : '@' + o.word + ' ', end = ta.selectionStart;
    ta.value = ta.value.slice(0, mSt.start) + ins + ta.value.slice(end);
    const p = mSt.start + ins.length; ta.setSelectionRange(p, p); ta.focus(); mClose();
  }
  ta.addEventListener('input', mCheck);
  ta.addEventListener('blur', () => setTimeout(mClose, 150));
  ta.addEventListener('keydown', e => {   // en captura: con la lista abierta, Enter elige (no envía)
    if (!mSt || !mBox) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); e.stopImmediatePropagation(); mSt.i = (mSt.i + (e.key === 'ArrowDown' ? 1 : -1) + mSt.opts.length) % mSt.opts.length; mDraw(); }
    else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); e.stopImmediatePropagation(); mPick(mSt.i); }
    else if (e.key === 'Escape') { e.stopImmediatePropagation(); mClose(); }
  }, true);
  $('ccw-file').addEventListener('change', e => { [...e.target.files].forEach(upload); e.target.value = ''; });
  $('ccw-att').addEventListener('click', e => { const b = e.target.closest('[data-rmatt]'); if (b) { S.att.splice(+b.dataset.rmatt, 1); drawAtt(); } });
  // Pegar una imagen o soltar archivos en la conversación
  ta.addEventListener('paste', e => { const fs = [...(e.clipboardData?.files || [])]; if (fs.length && S.open) { e.preventDefault(); fs.forEach(upload); } });
  $('ccw-conv').addEventListener('dragover', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); });
  $('ccw-conv').addEventListener('drop', e => { if (e.dataTransfer?.files?.length && S.open && S.canPost) { e.preventDefault(); [...e.dataTransfer.files].forEach(upload); } });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('ccw-panel').hidden && $('ccw-panel').contains(document.activeElement)) showPanel(false); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { sync(); markRead(); } else schedule(); });
  boot();
})();
