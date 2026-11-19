/**
 * chat.js — Pantalla del chat corporativo. Habla con api.php.
 * Estado en S; la lista y la conversación se redibujan desde el estado.
 * Sincroniza cada 2 s (12 s con la pestaña en segundo plano) desde el último evento visto.
 */
(() => {
  'use strict';
  const CH_TITLE = document.title;   // "Chat · <plataforma>": el contador de no leídos va adelante
  const API = 'api.php';
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Imágenes que no cargan (antes onerror en línea; CSP estricta): el avatar pasa a sus iniciales y la vista previa se quita.
  // El evento error no sube: se escucha en captura.
  document.addEventListener('error', e => {
    const t = e.target;
    if (!t || t.tagName !== 'IMG') return;
    if (t.hasAttribute('data-cc-fb')) t.replaceWith(t.getAttribute('data-cc-fb'));
    else if (t.hasAttribute('data-cc-rm')) t.remove();
  }, true);
  const LS = { get: (k, d) => { try { const v = localStorage.getItem('cc.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem('cc.' + k, JSON.stringify(v)); } catch (e) {} } };

  const S = {
    me: null, admin: false, users: {}, teams: [], cats: [], convs: {}, statuses: {}, maxBytes: 0, exts: [],
    view: 'home', open: null, preview: null, conv: {}, last_event: 0, reads: {}, typing: [],
    q: '', collapsed: LS.get('collapsed', {}), att: [], replyTo: null, editing: null,
    syncN: 0, failN: 0, browse: null, files: null, filesKind: '', aside: null, asideTab: 'info', info: null,
    prefs: {}, notifs: null, notifUnread: 0, notifFilter: 'all', thread: null, canTasks: false, saved: null, savedCat: '',
    search: { q: '', user: 0, conv: 0, team: 0, from: '', to: '', has: '' }, results: null, apps: null, notified: new Set(),
  };
  const cc = $('cc'), list = $('cc-list'), main = $('cc-main'), aside = $('cc-aside');

  // ── Utilidades ──
  async function api(action, data = {}, retry = true) {
    let r;
    try {
      r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CH': '1' }, body: JSON.stringify({ action, ...data }) });
    } catch (e) { throw new Error('Sin conexión con el servidor'); }
    if (r.status === 401 && retry) { await new Promise(ok => setTimeout(ok, 900)); return api(action, data, false); }
    let d = null; try { d = await r.json(); } catch (e) {}
    if (!d || !d.ok) { const err = new Error((d && d.error) || 'Algo falló en el servidor'); err.status = r.status; throw err; }
    return d;
  }
  function toast(msg, err) {
    const t = document.createElement('div'); t.className = 'cc-toast' + (err ? ' err' : '');
    t.innerHTML = `<i class="fa-solid ${err ? 'fa-circle-exclamation' : 'fa-circle-check'}"></i>${esc(msg)}`;
    $('cc-toasts').appendChild(t); setTimeout(() => t.remove(), err ? 5000 : 2600);
  }
  const fail = e => toast(e.message || String(e), true);
  const pad = n => String(n).padStart(2, '0');
  // Fechas: en la zona de quien mira, con assets/g7-time.js (ZONAS_HORARIAS.md). El servidor manda la hora de almacenamiento.
  const TZ = window.G7Time;
  const D = s => (TZ && TZ.parse(s)) || (s instanceof Date ? s : new Date(String(s).replace(' ', 'T')));
  const hhmm = s => TZ ? TZ.time(s) : (d => pad(d.getHours()) + ':' + pad(d.getMinutes()))(D(s));
  const sameDay = (a, b) => TZ ? TZ.sameDay(a, b) : D(a).toDateString() === D(b).toDateString();
  const dayLabel = s => TZ ? TZ.dayLabel(s) : D(s).toLocaleDateString('es');
  const shortWhen = s => !s ? '' : TZ ? TZ.short(s) : hhmm(s);
  const fullWhen = (s, o) => TZ ? TZ.fmt(s, o || { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : D(s).toLocaleString('es');
  /** Ubicación de una persona (la manda chat/lib.php): {cc, tz, zone, city}. Sin zona: null. */
  const locOf = uid => { const l = (S.users[uid] || {}).loc; return l && l.tz ? l : null; };
  /** Píldora discreta de la cabecera en un mensaje directo: bandera, hora de allá (se actualiza sola) y diferencia. */
  function tzPill(uid) {
    const l = locOf(uid); if (!TZ || !l || uid === S.me) return '';
    const diff = TZ.diffLabel(l.tz), place = TZ.countryName(l.cc) + (l.city ? ' · ' + l.city : '');
    return `<button class="cc-tz" data-tzuser="${uid}" aria-label="${esc(`Hora local de ${uname(uid)}: ${TZ.clock(l.tz)}, ${place}, ${diff}`)}">${TZ.flag(l.cc)}<span class="cc-tz-t"><b><span data-g7-clock="${esc(l.tz)}">${TZ.clock(l.tz)}</span>${TZ.isNight(l.tz) ? ' <i class="fa-solid fa-moon" title="Es de noche allá"></i>' : ''}</b><small>${esc(place)} · ${esc(diff)}</small></span></button>`;
  }
  /** Contenido de la ventanita de hora local (popover al pasar el mouse por el nombre o el avatar). */
  function tzTipHtml(uid) {
    const u = S.users[uid] || {}, l = locOf(uid);
    if (!TZ) return '';
    if (!l) return `<div class="cc-tzt-n">${esc(u.name || '')}</div><div class="cc-tzt-z">Todavía no eligió su zona horaria</div>`;
    const place = TZ.countryName(l.cc) + (l.city ? ', ' + l.city : '');
    return `<div class="cc-tzt-h">${TZ.flag(l.cc)}<span>${esc(place)}</span></div>
      <div class="cc-tzt-c"><span data-g7-clock="${esc(l.tz)}">${TZ.clock(l.tz)}</span>${TZ.isNight(l.tz) ? '<i class="fa-solid fa-moon"></i>' : ''}</div>
      <div class="cc-tzt-z">${esc(l.zone)} · ${esc(TZ.offsetLabel(l.tz))}</div>
      ${uid === S.me ? '' : `<div class="cc-tzt-d">${esc(TZ.diffLabel(l.tz))}</div>`}`;
  }
  const fmtSize = b => b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(0) + ' KB' : (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
  const fileIcon = (mime, name) => /^image\//.test(mime) ? 'fa-image' : /^video\//.test(mime) ? 'fa-film' : /^audio\//.test(mime) ? 'fa-music' : /pdf/.test(mime) ? 'fa-file-pdf'
    : /\.(xlsx?|csv)$/i.test(name) ? 'fa-file-excel' : /\.(docx?)$/i.test(name) ? 'fa-file-word' : /\.(pptx?)$/i.test(name) ? 'fa-file-powerpoint' : /\.(zip|rar|7z)$/i.test(name) ? 'fa-file-zipper' : 'fa-file';
  const uname = id => (S.users[id] || {}).name || 'Alguien';
  const initials = n => String(n || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
  function avatar(uid, size = 32, status = true) {
    const u = S.users[uid] || { name: '?' };
    const st = !status ? '' : `<i class="cc-st ${u.online ? u.status : 'off'}" title="${esc(statusLabel(u))}"></i>`;
    return `<span class="cc-av" style="--s:${size}px" data-user="${uid}"${u.avatar ? '' : ` data-g7-uid="${uid}"`}>${u.avatar ? `<img src="${esc(u.avatar)}" alt="" loading="lazy" data-cc-fb="${esc(initials(u.name))}">` : esc(initials(u.name))}${st}</span>`;
  }
  const statusLabel = u => !u.online ? 'Desconectado' : (S.statuses[u.status] || 'Disponible') + (u.status_text ? ' · ' + u.status_text : '');
  const convName = c => c.kind === 'dm' ? (c.peer === S.me ? uname(c.peer) + ' (tú)' : uname(c.peer)) : c.kind === 'channel' ? c.name : c.name;
  function convIcon(c, size = 32) {
    if (c.kind === 'dm') return avatar(c.peer, size);
    if (c.avatar) return `<span class="cc-av" style="--s:${size}px"><img src="${esc(c.avatar)}" alt="" loading="lazy"></span>`;
    if (c.kind === 'group') return `<span class="cc-hash" style="--s:${size}px"><i class="fa-solid fa-user-group" style="font-size:.8em"></i></span>`;
    return `<span class="cc-hash" style="--s:${size}px">${c.visibility === 'private' ? '<i class="fa-solid fa-lock" style="font-size:.75em"></i>' : c.announce ? '<i class="fa-solid fa-bullhorn" style="font-size:.75em"></i>' : '#'}</span>`;
  }
  const convTitleHtml = c => c.kind === 'channel' ? `<span style="color:var(--g7-text-4)">#</span><span>${esc(c.name)}</span>` : `<span>${esc(convName(c))}</span>`;
  const newId = () => 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  const hex32 = () => [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');

  // ── Formato de mensajes (seguro: primero se escapa todo) ──
  function fmt(text) {
    if (!text) return '';
    const keep = [];
    const hold = h => { keep.push(h); return '\u0000' + (keep.length - 1) + '\u0000'; };
    let s = String(text).replace(/```(?:[a-z0-9]+\n)?([\s\S]*?)```/gi, (_, code) => hold(`<pre><code>${esc(code.replace(/^\n|\n$/g, ''))}</code></pre>`));
    s = s.replace(/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]+)\)/g, (_, t, u) => hold(`<a href="${esc(u)}" target="_blank" rel="noopener noreferrer">${esc(t)}</a>`));
    s = esc(s);
    if (window.CCRefs) s = s.replace(/\[\[(?:(cliente|contenido|tarea|ticket):\d{1,9}|jira:[A-Z][A-Z0-9_]{0,19}-\d{1,9})\]\]/g, t => hold(CCRefs.fmt(t)));   // vínculos (chat/refs.js)
    s = s.replace(/`([^`\n]+)`/g, (_, c) => hold(`<code>${c}</code>`));
    s = s.replace(/\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)\]'"&]/g, u => hold(`<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`));
    s = s.replace(/(^|[\s(])@([\p{L}\p{N}_.\-]{2,50})/gu, (all, pre, w) => {
      const k = w.replace(/[.\-]+$/, '').toLowerCase();
      if (['canal', 'todos', 'aqui', 'aquí', 'here', 'channel', 'everyone'].includes(k)) return pre + hold(`<span class="cc-at all">@${esc(k)}</span>`) + w.slice(k.length);
      const u = Object.values(S.users).find(x => (x.username || '').toLowerCase() === k);
      return u ? pre + hold(`<span class="cc-at${u.id === S.me ? ' me' : ''}" data-user="${u.id}">@${esc(u.name)}</span>`) + w.slice(k.length) : all;
    });
    s = s.replace(/(^|[\s(])\*\*([^*\n]+)\*\*/g, '$1<b>$2</b>').replace(/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,!?:;])/g, '$1<b>$2</b>')
         .replace(/(^|[\s(])__([^_\n]+)__/g, '$1<u>$2</u>').replace(/(^|[\s(])_([^_\n]+)_(?=$|[\s).,!?:;])/g, '$1<i>$2</i>')
         .replace(/(^|[\s(])~([^~\n]+)~(?=$|[\s).,!?:;])/g, '$1<s>$2</s>');
    // Citas y listas por línea
    const lines = s.split('\n'), out = [];
    let mode = null, buf = [];
    const flush = () => {
      if (!mode) return;
      if (mode === 'q') out.push(`<blockquote>${buf.join('<br>')}</blockquote>`);
      else out.push(`<${mode}>${buf.map(x => `<li>${x}</li>`).join('')}</${mode}>`);
      mode = null; buf = [];
    };
    for (const ln of lines) {
      let m;
      if ((m = ln.match(/^&gt;\s?(.*)$/))) { if (mode !== 'q') { flush(); mode = 'q'; } buf.push(m[1]); }
      else if ((m = ln.match(/^\s*[-•*]\s+(.+)$/))) { if (mode !== 'ul') { flush(); mode = 'ul'; } buf.push(m[1]); }
      else if ((m = ln.match(/^\s*\d+[.)]\s+(.+)$/))) { if (mode !== 'ol') { flush(); mode = 'ol'; } buf.push(m[1]); }
      else { flush(); out.push(ln + '\n'); }
    }
    flush();
    s = out.join('').replace(/\n$/, '');
    s = s.replace(/\u0000(\d+)\u0000/g, (_, i) => keep[+i]);
    return s;
  }
  const onlyEmoji = t => t && t.length <= 12 && /^(\p{Extended_Pictographic}|\p{Emoji_Component}|‍|️|\s)+$/u.test(t) && !/^[\d#*\s]+$/.test(t);

  // ── Arranque ──
  async function boot() {
    const tb = document.querySelector('#g7-topbar, .g7-topbar');
    const setTop = () => { const b = tb ? Math.max(0, tb.getBoundingClientRect().bottom) : 0; cc.style.setProperty('--cc-top', b + 'px'); };
    setTop(); addEventListener('resize', setTop); setTimeout(setTop, 300);
    list.innerHTML = '<div class="cc-lh"><h2>Chat</h2></div><div class="cc-scroll">' + '<div class="cc-sk"></div>'.repeat(8) + '</div>';
    try {
      const d = await api('boot');
      S.me = d.me.id; S.admin = d.me.admin; S.teams = d.teams; S.cats = d.categories; S.statuses = d.statuses; S.maxBytes = d.max_bytes; S.exts = d.exts;
      S.prefs = d.prefs || {}; S.notifUnread = d.notif_unread || 0; S.canTasks = !!d.tasks;
      if (S.admin) document.querySelector('.cc-rb[data-href="admin"]')?.removeAttribute('hidden');
      d.users.forEach(u => { S.users[u.id] = u; });
      d.convs.forEach(c => { S.convs[c.id] = c; });
      S.last_event = d.last_event;
    } catch (e) {
      list.innerHTML = `<div class="cc-empty"><i class="fa-solid fa-plug-circle-xmark big"></i><b>No se pudo abrir el chat</b>${esc(e.message)}<br><br><button class="cc-btn" data-cc-reload>Reintentar</button></div>`;
      list.querySelector('[data-cc-reload]').addEventListener('click', () => location.reload());
      return;
    }
    renderMe(); renderList(); updateBadges();
    window.CCX = { S, api, openConv, openDM, react, curConv, uname, avatar, esc, renderMsgs, renderList, toast, fail };
    emit('boot');
    const qs = new URLSearchParams(location.search);
    if (qs.get('m')) gotoMessage(+qs.get('m'));
    else if (qs.get('c') && S.convs[+qs.get('c')]) openConv(+qs.get('c'));
    else if (qs.get('u')) openDM(+qs.get('u'));
    setTimeout(sync, 1500);
  }

  // ── Sincronización ──
  let syncTimer = null, syncing = false;
  async function sync() {
    clearTimeout(syncTimer);
    if (syncing) return;
    syncing = true;
    let more = false;
    try {
      const presence = S.syncN++ % 15 === 0;
      const r = await api('sync', { after: S.last_event, open: S.open || 0, presence });
      if (S.failN >= 2) { toast('Conexión recuperada'); resendQueued(); }
      S.failN = 0; conn(true);
      S.last_event = r.last_event; more = r.more;
      if (r.categories) S.cats = r.categories;
      (r.convs || []).forEach(c => { S.convs[c.id] = c; });
      (r.gone || []).forEach(id => {
        delete S.convs[id];
        if (S.open === id && !(S.preview && S.preview.id === id)) { closeConv(); toast('Ya no formas parte de esa conversación', true); }
      });
      if (r.presence) r.presence.forEach(p => { if (S.users[p.id]) Object.assign(S.users[p.id], p); });
      let touchedOpen = false;
      (r.messages || []).forEach(m => { if (upsert(m) && m.conv_id === S.open) touchedOpen = true; maybeNotify(m); });
      if (r.notifs) onNotifs(r.notifs, r.notif_unread);
      if (r.reads && S.open) { S.reads[S.open] = r.reads; touchedOpen = true; }
      if (r.typing && S.open) { S.typing = r.typing; renderTyping(); }
      renderList(); updateBadges(); if (r.presence) renderMe();
      if (S.open && touchedOpen) { renderMsgs(); markRead(); }
      if (S.open && r.convs && r.convs.some(c => c.id === S.open)) renderHead();
    } catch (e) {
      S.failN++;
      if (S.failN >= 2) conn(false);
      if (S.failN === 2 && S.open) renderMsgs();
    } finally { syncing = false; }
    const wait = more ? 50 : document.hidden ? 12000 : S.failN ? Math.min(15000, 2000 * 2 ** S.failN) : 2000;
    syncTimer = setTimeout(sync, wait);
  }
  function conn(ok) {
    let b = $('cc-conn');
    if (!b) { b = document.createElement('div'); b.id = 'cc-conn'; b.className = 'cc-conn'; b.innerHTML = '<i class="fa-solid fa-wifi"></i> Reconectando… los mensajes se mandan apenas vuelva la conexión'; main.appendChild(b); }
    b.classList.toggle('on', !ok);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { sync(); markRead(); } });
  addEventListener('online', () => sync());
  addEventListener('focus', () => markRead());

  /** Mete un mensaje (nuevo o cambiado) en la conversación cargada. Devuelve true si cambió algo visible. */
  function upsert(m) {
    if (m.parent_id) { threadUpsert(m); return false; }
    if (S.thread && S.thread.root.id === m.id) { S.thread.root = m; renderThread(); }
    const C = S.conv[m.conv_id];
    if (!C) return false;
    if (m.parent_id) { threadUpsert(m); return false; }
    let i = C.list.findIndex(x => x.id === m.id || (m.client_id && x.client_id === m.client_id && x.pending !== undefined));
    if (i >= 0) { C.list[i] = m; return true; }
    if (C.moreAfter) return false;
    C.list.push(m); C.list.sort((a, b) => (a.pending ? 1e15 : a.id) - (b.pending ? 1e15 : b.id));
    if (m.user_id !== S.me && S.open === m.conv_id && !atBottom()) S.newBelow = (S.newBelow || 0) + 1;
    return true;
  }

  // ── Lista (panel del medio) ──
  function sortedConvs(filter) {
    return Object.values(S.convs).filter(c => !c.archived).filter(filter || (() => true));
  }
  const byRecent = (a, b) => String(b.last_msg_at || '').localeCompare(String(a.last_msg_at || '')) || b.id - a.id;
  function matchQ(c) { return !S.q || convName(c).toLowerCase().includes(S.q) || (c.kind !== 'dm' && (c.topic || '').toLowerCase().includes(S.q)); }

  function rowHtml(c, preview) {
    const unread = c.unread > 0 && !c.muted;
    const last = c.last;
    let prev = '';
    if (preview && last) {
      const who = last.user_id === S.me ? 'Tú: ' : c.kind !== 'dm' ? uname(last.user_id).split(' ')[0] + ': ' : '';
      prev = last.kind === 'system' ? uname(last.user_id).split(' ')[0] + ' ' + last.body : who + (String(last.body || '').replace(/\[\[(cliente|contenido|tarea|ticket):\d+\]\]/g, '🔗 $1').replace(/\[\[jira:([A-Z0-9_]+-\d+)\]\]/g, '🔗 $1') || '📎 Archivo');
    }
    return `<button class="cc-row${S.open === c.id ? ' on' : ''}${c.unread > 0 ? ' unread' : ''}${c.muted ? ' muted' : ''}" data-conv="${c.id}" ${c.unread ? `aria-label="${esc(convName(c))}, ${c.unread} sin leer"` : ''}>
      ${convIcon(c, preview ? 34 : 26)}
      <span class="cc-rt"><span class="cc-rn"><span>${esc(convName(c))}</span>${c.muted ? '<i class="fa-solid fa-bell-slash"></i>' : ''}</span>${preview ? `<span class="cc-rp">${esc(prev) || '&nbsp;'}</span>` : ''}</span>
      <span class="cc-rm">${preview && c.last_msg_at ? `<time>${shortWhen(c.last_msg_at)}</time>` : ''}${c.unread ? `<span class="cc-badge">${c.unread > 99 ? '99+' : c.unread}</span>` : ''}</span>
    </button>`;
  }
  function section(key, title, rows, action) {
    const closed = S.collapsed[key];
    return `<div class="cc-sec${closed ? ' closed' : ''}" data-sec="${esc(key)}"><button class="cc-sec-h" data-toggle="${esc(key)}"><i class="fa-solid fa-chevron-down ch"></i>${esc(title)}${action ? `<span class="cc-sec-a" data-act="${action}" title="Agregar" role="button" aria-label="Agregar"><i class="fa-solid fa-plus"></i></span>` : ''}</button><div class="cc-rows">${rows}</div></div>`;
  }
  function renderList() {
    const keepScroll = list.querySelector('.cc-scroll')?.scrollTop || 0;
    const hadFocus = document.activeElement && document.activeElement.id === 'cc-q';
    const head = (title, btn) => `<div class="cc-lh"><h2>${title}</h2>${btn || ''}</div>
      <div class="cc-search"><i class="fa-solid fa-magnifying-glass"></i><input id="cc-q" placeholder="Buscar conversación o persona" value="${esc(S.q)}" autocomplete="off" aria-label="Buscar conversación o persona"><kbd>Ctrl K</kbd></div>`;
    let body = '';
    if (S.view === 'home') {
      const favs = sortedConvs(c => c.favorite && matchQ(c)).sort(byRecent);
      const chans = sortedConvs(c => c.kind === 'channel' && !c.favorite && matchQ(c));
      const groups = sortedConvs(c => c.kind === 'group' && !c.favorite && matchQ(c)).sort(byRecent);
      const dms = sortedConvs(c => c.kind === 'dm' && !c.favorite && matchQ(c)).sort(byRecent);
      if (favs.length) body += section('fav', 'Favoritos', favs.map(c => rowHtml(c, false)).join(''));
      const cats = [...S.cats].sort((a, b) => a.sort - b.sort || a.id - b.id);
      const byCat = id => chans.filter(c => (c.category_id || 0) === id).sort((a, b) => a.sort - b.sort || a.name.localeCompare(b.name));
      cats.forEach(k => { const r = byCat(+k.id); if (r.length) body += section('cat' + k.id, k.name, r.map(c => rowHtml(c, false)).join('')); });
      const known = new Set(cats.map(k => +k.id));
      const loose = chans.filter(c => !c.category_id || !known.has(c.category_id));
      body += section('chans', 'Canales', loose.map(c => rowHtml(c, false)).join('') + (S.q ? '' : `<button class="cc-row" data-act="browse"><span class="cc-hash" style="--s:26px"><i class="fa-solid fa-plus" style="font-size:.8em"></i></span><span class="cc-rt" style="color:var(--g7-text-3)">Ver todos los canales</span></button>`), 'new-channel');
      if (groups.length || !S.q) body += section('groups', 'Grupos', groups.map(c => rowHtml(c, false)).join('') || `<div class="cc-empty" style="padding:8px 10px;text-align:left">Todavía no estás en ningún grupo.</div>`, 'new-group');
      body += section('dms', 'Mensajes directos', dms.map(c => rowHtml(c, false)).join(''), 'new-dm');
      body += peopleMatches();
      list.innerHTML = head('Chat', `<button class="cc-ib pri" data-act="new-menu" title="Nuevo" aria-label="Nuevo mensaje, grupo o canal"><i class="fa-solid fa-pen-to-square"></i></button>`) + `<div class="cc-scroll">${body}</div>`;
    } else if (S.view === 'dms') {
      const rows = sortedConvs(c => c.kind !== 'channel' && matchQ(c)).sort(byRecent);
      body = rows.map(c => rowHtml(c, true)).join('') + peopleMatches();
      if (!rows.length && !S.q) body = `<div class="cc-empty"><i class="fa-regular fa-comments big"></i><b>Sin mensajes directos</b>Escribile a alguien del equipo para empezar.<br><br><button class="cc-btn pri" data-act="new-dm"><i class="fa-solid fa-pen-to-square"></i> Nuevo mensaje</button></div>`;
      list.innerHTML = head('Mensajes', `<button class="cc-ib pri" data-act="new-menu" title="Nuevo" aria-label="Nuevo mensaje o grupo"><i class="fa-solid fa-pen-to-square"></i></button>`) + `<div class="cc-scroll">${body}</div>`;
    } else if (S.view === 'people') {
      // Personas: todo el equipo (activas), primero quienes están en línea. Tocar la fila abre la conversación; la foto, su tarjeta (con lo que está trabajando)
      const q = S.q, all = Object.values(S.users).filter(u => u.active && (!q || [u.name, u.username, u.position, u.dept].some(x => String(x || '').toLowerCase().includes(q))))
        .sort((a, b) => (b.online ? 1 : 0) - (a.online ? 1 : 0) || String(a.name).localeCompare(String(b.name), 'es'));
      const prow = u => `<button class="cc-row cc-prow" data-dm="${u.id}"><span data-user="${u.id}" title="Ver su tarjeta">${avatar(u.id, 32)}</span><span class="cc-rt"><span class="cc-rn"><span>${esc(u.name)}${u.id === S.me ? ' <small>(tú)</small>' : ''}</span></span><span class="cc-rp">${esc(u.status_text || [u.position, u.dept].filter(Boolean).join(' · ') || '@' + (u.username || ''))}</span></span><i class="cc-st ${u.online ? u.status : 'off'}" style="position:static;margin-left:auto"></i></button>`;
      const on = all.filter(u => u.online), off = all.filter(u => !u.online);
      body = all.length ? section('pon', `En línea · ${on.length}`, on.map(prow).join('') || '<div class="cc-empty" style="padding:10px">Nadie en línea ahora.</div>') + section('poff', `Desconectados · ${off.length}`, off.map(prow).join(''))
        : `<div class="cc-empty"><i class="fa-solid fa-magnifying-glass big"></i><b>Nadie con «${esc(q)}»</b>Prueba con otro nombre.</div>`;
      list.innerHTML = head(`Personas <small style="font-size:13px;opacity:.6">${Object.values(S.users).filter(u => u.active).length}</small>`) + `<div class="cc-scroll cc-people">${body}</div>`;
    } else if (S.view === 'channels') {
      list.innerHTML = head('Canales', `<button class="cc-ib pri" data-act="new-channel" title="Crear canal" aria-label="Crear canal"><i class="fa-solid fa-plus"></i></button>`) + `<div class="cc-scroll" id="cc-browse">${browseHtml()}</div>`;
    } else if (S.view === 'files') {
      list.innerHTML = `<div class="cc-lh"><h2>Archivos</h2></div>
        <div class="cc-search"><i class="fa-solid fa-magnifying-glass"></i><input id="cc-fq" placeholder="Buscar archivo por nombre" value="${esc(S.fq || '')}" autocomplete="off" aria-label="Buscar archivo"></div>
        <div style="padding:0 12px 6px"><div class="cc-tabs" style="margin:0">${[['', 'Todos'], ['img', 'Imágenes'], ['docs', 'Documentos'], ['media', 'Audio y video']].map(([k, l]) => `<button data-fkind="${k}" class="${S.filesKind === k ? 'on' : ''}">${l}</button>`).join('')}</div></div>
        <div class="cc-scroll">${filesHtml()}</div>`;
    }
    else if (S.view === 'activity') {
      list.innerHTML = `<div class="cc-lh"><h2>Actividad</h2>${S.notifUnread ? '<button class="cc-btn sm" data-act="notif-allread">Marcar todo leído</button>' : ''}</div>
        <div style="padding:0 12px 6px"><div class="cc-tabs" style="margin:0">${[['all', 'Todo'], ['mention', 'Menciones y respuestas'], ['unread', 'Sin leer']].map(([k, l]) => `<button data-nfilter="${k}" class="${S.notifFilter === k ? 'on' : ''}">${l}</button>`).join('')}</div></div>
        <div class="cc-scroll">${activityHtml()}</div>`;
    } else if (S.view === 'saved') {
      list.innerHTML = `<div class="cc-lh"><h2>Guardados</h2></div><div class="cc-scroll">${savedHtml()}</div>`;
    } else if (S.view === 'search') {
      list.innerHTML = `<div class="cc-lh"><h2>Buscar</h2></div><div class="cc-scroll" style="padding-top:0">${searchFormHtml()}<div id="cc-sres">${resultsHtml()}</div></div>`;
    } else if (S.view === 'apps') {
      list.innerHTML = `<div class="cc-lh"><h2>Herramientas</h2></div><div class="cc-scroll">${appsHtml()}</div>`;
    } else if (S.view === 'settings') {
      list.innerHTML = `<div class="cc-lh"><h2>Configuración</h2></div><div class="cc-scroll">${settingsHtml()}</div>`;
    }
    const sc = list.querySelector('.cc-scroll'); if (sc) sc.scrollTop = keepScroll;
    setupDnD();
    if (hadFocus) { const q = $('cc-q'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
    if (S.view === 'files' && document.activeElement?.id !== 'cc-fq' && S.fqFocus) { const q = $('cc-fq'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
  }
  function peopleMatches() {
    if (!S.q) return '';
    const withDm = new Set(Object.values(S.convs).filter(c => c.kind === 'dm').map(c => c.peer));
    const ppl = Object.values(S.users).filter(u => u.active && !withDm.has(u.id) && (u.name.toLowerCase().includes(S.q) || (u.username || '').toLowerCase().includes(S.q))).slice(0, 8);
    if (!ppl.length) return Object.values(S.convs).some(matchQ) ? '' : `<div class="cc-empty"><i class="fa-solid fa-magnifying-glass big"></i><b>Nada con «${esc(S.q)}»</b>Prueba con otro nombre.</div>`;
    return section('ppl', 'Personas', ppl.map(u => `<button class="cc-row" data-dm="${u.id}">${avatar(u.id, 26)}<span class="cc-rt"><span class="cc-rn"><span>${esc(u.name)}</span></span><span class="cc-rp">${esc(u.position || u.dept || 'Escribile un mensaje')}</span></span></button>`).join(''));
  }
  function browseHtml() {
    if (!S.browse) return '<div class="cc-sk"></div>'.repeat(5);
    const rows = S.browse.filter(c => !S.q || c.name.includes(S.q) || (c.topic || '').toLowerCase().includes(S.q));
    if (!rows.length) return `<div class="cc-empty"><i class="fa-solid fa-hashtag big"></i><b>${S.q ? 'Ningún canal coincide' : 'Todavía no hay canales'}</b>${S.q ? '' : 'Crea el primero para ordenar las conversaciones por tema.'}</div>`;
    const catName = id => (S.cats.find(k => +k.id === id) || {}).name || 'Sin categoría';
    const groups = {};
    rows.forEach(c => { (groups[c.category_id || 0] ||= []).push(c); });
    const order = [...S.cats].sort((a, b) => a.sort - b.sort).map(k => +k.id).concat(0);
    return order.filter(k => groups[k]).map(k => `<div class="cc-sec-h" style="margin:12px 0 6px">${esc(catName(k))}</div>` + groups[k].map(c => `
      <div class="cc-chcard">
        <span class="cc-hash" style="--s:34px">${c.visibility === 'private' ? '<i class="fa-solid fa-lock" style="font-size:.75em"></i>' : c.announce ? '<i class="fa-solid fa-bullhorn" style="font-size:.75em"></i>' : '#'}</span>
        <span class="cc-rt"><b>${esc(c.name)}${c.visibility === 'restricted' ? ' <span class="cc-tag">Restringido</span>' : ''}</b><p>${esc(c.topic || 'Sin descripción')}</p><small>${c.members} ${c.members === 1 ? 'miembro' : 'miembros'}</small></span>
        ${c.joined ? `<button class="cc-btn sm" data-conv="${c.id}">Abrir</button>` : `<span style="display:flex;flex-direction:column;gap:5px"><button class="cc-btn sm pri" data-join="${c.id}">Unirme</button><button class="cc-btn sm" data-peek="${c.id}">Ver</button></span>`}
      </div>`).join('')).join('');
  }
  async function loadBrowse() { try { S.browse = (await api('browse')).channels; } catch (e) { S.browse = []; fail(e); } if (S.view === 'channels') renderList(); }
  function filesHtml() {
    if (!S.files) return '<div class="cc-sk"></div>'.repeat(6);
    if (!S.files.length) return `<div class="cc-empty"><i class="fa-regular fa-folder-open big"></i><b>${S.fq ? 'Ningún archivo coincide' : 'Sin archivos todavía'}</b>Lo que se comparta en tus conversaciones aparece acá.</div>`;
    return S.files.map(f => { const c = S.convs[f.conv_id]; return `<a class="cc-afile" href="${esc(f.url)}" target="_blank" rel="noopener" data-fileconv="${f.conv_id}" data-filemsg="${f.message_id}">
      <span class="fi">${/^image\//.test(f.mime) ? `<img src="${esc(f.url)}" alt="" loading="lazy">` : `<i class="fa-solid ${fileIcon(f.mime, f.name)}"></i>`}</span>
      <span class="cc-rt" style="flex:1;min-width:0"><b>${esc(f.name)}</b><small>${fmtSize(f.size)} · ${esc(uname(f.user_id).split(' ')[0])} · ${c ? (c.kind === 'channel' ? '#' : '') + esc(convName(c)) + ' · ' : ''}${shortWhen(f.created_at)}</small></span></a>`; }).join('');
  }
  let filesT = null;
  async function loadFiles() { try { S.files = (await api('files', { q: S.fq || '', kind: S.filesKind })).files; } catch (e) { S.files = []; fail(e); } if (S.view === 'files') renderList(); }

  function updateBadges() {
    let all = 0, dm = 0;
    Object.values(S.convs).forEach(c => { if (c.muted || !c.unread) return; all += c.unread; if (c.kind !== 'channel') dm += c.unread; });
    const set = (id, n) => { const b = $(id); if (!b) return; b.hidden = !n; b.textContent = n > 99 ? '99+' : n; };
    set('cc-n-home', all); set('cc-n-dms', dm); set('cc-n-act', S.notifUnread);
    document.title = (all ? `(${all}) ` : '') + CH_TITLE;
  }
  function renderMe() {
    const u = S.users[S.me]; if (!u) return;
    $('cc-me').innerHTML = avatar(S.me, 36);
    $('cc-me').dataset.tip = statusLabel(u);
  }

  // ── Abrir una conversación ──
  async function openConv(id, opt = {}) {
    if (S.editing) S.editing = null;
    const prev = S.open;
    S.open = id; S.preview = opt.preview || null; S.replyTo = null; S.typing = []; S.newBelow = 0;
    if (prev !== id) { S.att = []; S.draft = LS.get('draft.' + id, ''); }
    cc.classList.add('open');
    history.replaceState(null, '', '?c=' + id);
    renderList();
    renderMain();
    if (S.aside && S.aside !== 'thread') loadInfo();
    try {
      const d = await api('history', opt.around ? { conv: id, around: opt.around, limit: 60 } : { conv: id });
      if (S.open !== id) return;
      S.conv[id] = { list: d.messages, moreBefore: d.more_before, moreAfter: d.more_after, firstUnread: (d.messages.find(m => m.id > d.last_read_id && m.user_id !== S.me && m.kind !== 'system') || {}).id || 0,
        member: d.member, canPost: d.can_post, canManage: d.can_manage, canJoin: d.can_join };
      S.reads[id] = d.reads;
      renderMain();
      const C = S.conv[id];
      requestAnimationFrame(() => {
        const box = $('cc-msgs'); if (!box) return;
        if (opt.around) { const el = $('m-' + opt.around); if (el) { el.scrollIntoView({ block: 'center' }); el.classList.add('hl'); } }
        else if (C.firstUnread) { const el = $('cc-newline'); if (el) el.scrollIntoView({ block: 'center' }); else box.scrollTop = box.scrollHeight; }
        else box.scrollTop = box.scrollHeight;
        markRead();
      });
    } catch (e) { fail(e); if (e.status === 403 || e.status === 404) closeConv(); }
  }
  function closeConv() {
    S.open = null; S.preview = null; cc.classList.remove('open', 'aside-on'); S.aside = null;
    history.replaceState(null, '', location.pathname);
    main.innerHTML = $('cc-welcome-tpl').innerHTML; renderList(); emit('welcome');
  }
  async function openDM(uid) {
    try { const d = await api('dm_open', { user_id: uid }); S.convs[d.conv.id] = d.conv; S.view = S.view === 'channels' || S.view === 'files' ? 'home' : S.view; setRail(); openConv(d.conv.id); } catch (e) { fail(e); }
  }
  async function gotoMessage(mid) {
    try { const d = await api('locate', { id: mid }); openConv(d.conv, { around: d.id }); } catch (e) { fail(e); }
  }
  const curConv = () => S.convs[S.open] || S.preview;

  // ── Conversación (panel principal) ──
  function renderMain() {
    const c = curConv();
    if (!c) { main.innerHTML = $('cc-welcome-tpl').innerHTML; emit('welcome'); return; }
    main.innerHTML = `
      <header class="cc-mh" id="cc-mh"></header>
      <div class="cc-msgs" id="cc-msgs" tabindex="-1" aria-live="polite"></div>
      <button class="cc-jump" id="cc-jump"><i class="fa-solid fa-arrow-down"></i> Mensajes nuevos <b id="cc-jumpn"></b></button>
      <div class="cc-comp" id="cc-comp"></div>`;
    if (S.failN >= 2) conn(false);
    renderHead(); renderMsgs(); renderComposer();
    $('cc-msgs').addEventListener('scroll', onScroll, { passive: true });
  }
  function renderHead() {
    const c = curConv(), h = $('cc-mh'); if (!c || !h) return;
    let sub = '';
    if (c.kind === 'dm') { const u = S.users[c.peer] || {}; sub = statusLabel(u) + (u.position ? ' · ' + u.position : ''); }
    else sub = (c.topic ? c.topic + ' · ' : '') + `${(c.members || []).length || c.members} ${((c.members || []).length || c.members) === 1 ? 'miembro' : 'miembros'}`;
    const isMem = !!S.convs[c.id];
    h.innerHTML = `
      <button class="cc-ib cc-back" data-act="back" aria-label="Volver"><i class="fa-solid fa-arrow-left"></i></button>
      ${convIcon(c, 36)}
      <button class="cc-mh-t" data-act="info"><b>${convTitleHtml(c)}${c.kind === 'channel' && c.announce ? ' <span class="cc-tag">Anuncios</span>' : ''}${c.muted ? ' <i class="fa-solid fa-bell-slash" style="font-size:11px;color:var(--g7-text-4)"></i>' : ''}</b><small>${esc(sub)}</small></button>
      ${c.kind === 'dm' ? tzPill(c.peer) : ''}
      <div class="cc-mh-a">
        ${isMem ? `<button class="cc-ib${c.favorite ? ' on' : ''}" data-act="fav" title="${c.favorite ? 'Quitar de favoritos' : 'Marcar como favorito'}" aria-label="Favorito" aria-pressed="${c.favorite}"><i class="fa-${c.favorite ? 'solid' : 'regular'} fa-star"></i></button>
        <button class="cc-ib" data-act="mute" title="${c.muted ? 'Activar avisos' : 'Silenciar'}" aria-label="Silenciar" aria-pressed="${c.muted}"><i class="fa-solid ${c.muted ? 'fa-bell-slash' : 'fa-bell'}"></i></button>` : ''}
        <button class="cc-ib${S.aside === 'info' ? ' on' : ''}" data-act="info" title="Detalles" aria-label="Detalles"><i class="fa-solid fa-circle-info"></i></button>
      </div>`;
    emit('head');
  }

  function msgHtml(m, prev, C, inThread) {
    const mine = m.user_id === S.me;
    if (m.kind === 'system') return `<div class="cc-m sys" id="${inThread ? 't' : 'm'}-${m.id}" data-id="${m.id}"><span class="cc-sysi"><i class="fa-solid fa-circle-info"></i></span><div class="cc-sys"><b>${esc(uname(m.user_id))}</b> ${esc(m.body)} · <time>${hhmm(m.created_at)}</time></div></div>`;
    const special = ['poll', 'announce', 'reminder'];
    const cont = prev && prev.kind !== 'system' && !special.includes(prev.kind) && !special.includes(m.kind) && prev.user_id === m.user_id && !prev.deleted && sameDay(prev.created_at, m.created_at)
      && (D(m.created_at) - D(prev.created_at)) < 5 * 60000 && !m.reply_to && !m.fwd && !(prev.thread_count && !inThread);
    const conv = S.convs[m.conv_id];
    let body = '';
    if (m.pinned && !inThread) body += `<div class="cc-pinmark"><i class="fa-solid fa-thumbtack"></i> Fijado</div>`;
    if (m.deleted) body += `<div class="cc-del"><i class="fa-solid fa-ban"></i> Se eliminó este mensaje</div>`;
    else if (S.editing === m.id) body += `<div class="cc-editbox"><textarea id="cc-edit" aria-label="Editar mensaje">${esc(m.body)}</textarea><div><button class="cc-btn sm pri" data-act="edit-save">Guardar</button><button class="cc-btn sm" data-act="edit-cancel">Cancelar</button><span>Enter guarda · Esc cancela</span></div></div>`;
    else {
      if (m.reply_to) body += `<div class="cc-quote" data-goto="${m.reply_to.id}">${m.reply_to.deleted ? '<span><i>Mensaje eliminado</i></span>' : `<span><b>${esc(uname(m.reply_to.user_id))}</b>${esc(m.reply_to.body || '📎 Archivo')}</span>`}</div>`;
      if (m.kind === 'announce' && m.ann) body += `<div class="cc-ann">${m.ann.image ? `<img src="${esc(m.ann.image)}" alt="" loading="lazy">` : ''}<div class="pad"><span class="tag"><i class="fa-solid fa-bullhorn"></i> Anuncio</span><h3>${esc(m.ann.title)}</h3>${m.body ? `<div class="cc-body">${fmt(m.body)}</div>` : ''}</div></div>`;
      else if (m.kind === 'reminder') body += `<div class="cc-remind"><i class="fa-regular fa-bell"></i><div><b>Recordatorio</b><div class="cc-body">${fmt(m.body)}</div></div></div>`;
      else if (m.body) body += `<div class="cc-body${onlyEmoji(m.body) ? ' cc-big' : ''}">${fmt(m.body)}${m.edited ? '<span class="cc-edited">(editado)</span>' : ''}</div>`;
      if (m.fwd) body += `<div class="cc-fwd" data-gomsg="${m.fwd.id}" title="Ir al mensaje original"><small><i class="fa-solid fa-share"></i> Reenviado · <b>${esc(uname(m.fwd.user_id))}</b> en ${esc(m.fwd.conv_name)} · ${esc(shortWhen(m.fwd.created_at))}</small>${m.fwd.body ? `<div class="cc-body">${fmt(m.fwd.body)}</div>` : ''}</div>`;
      if (m.poll) body += pollHtml(m);
      const files = (m.files || []).filter(f => !(m.ann && m.ann.image && m.ann.image === f.url));
      if (files.length) body += `<div class="cc-files">${files.map(fileHtml).join('')}</div>`;
      if (m.ref && m.ref.url) body += `<a class="cc-ref" href="${esc(m.ref.url)}" target="_blank" rel="noopener"><i class="fa-solid fa-link"></i>${esc(m.ref.title || m.ref.url)}</a>`;
      if (m.preview) body += `<div class="cc-prev"><div class="pv-t"><small>${esc(m.preview.site)}</small><a href="${esc(m.preview.url)}" target="_blank" rel="noopener noreferrer">${esc(m.preview.title)}</a>${m.preview.desc ? `<p>${esc(m.preview.desc)}</p>` : ''}</div>${m.preview.image ? `<img src="${esc(m.preview.image)}" alt="" loading="lazy" referrerpolicy="no-referrer" data-cc-rm>` : ''}${mine ? '<button class="pv-x" data-act="unfurl-hide" title="Quitar vista previa" aria-label="Quitar vista previa"><i class="fa-solid fa-xmark"></i></button>' : ''}</div>`;
      if ((m.tasks && m.tasks.length) || (m.tickets && m.tickets.length)) body += `<div class="cc-tasks">${(m.tasks || []).map(t => `<a class="cc-taskchip" href="/toolz/tareas.php?${t.board ? 'b=' + (+t.board) + '&' : ''}t=${+t.id}" target="_blank" rel="noopener"><i class="fa-solid fa-list-check"></i> Tarea: ${esc(t.title)}</a>`).join('')}${(m.tickets || []).map(t => `<a class="cc-taskchip" href="/toolz/trabajo/#tickets/${+t.id}" target="_blank" rel="noopener"><i class="fa-solid fa-ticket"></i> Ticket T-${String(+t.id).padStart(4, '0')}: ${esc(t.title)}</a>`).join('')}</div>`;
      if (m.reactions && m.reactions.length) body += `<div class="cc-reacts">${m.reactions.map(r => `<button class="cc-react${r.mine ? ' mine' : ''}" data-react="${esc(r.emoji)}" title="${esc(r.users.map(uname).join(', '))}">${r.emoji}<span>${r.users.length}</span></button>`).join('')}${conv ? '<button class="cc-react add" data-act="react-more" aria-label="Agregar reacción"><i class="fa-regular fa-face-smile"></i></button>' : ''}</div>`;
      if (!inThread && m.thread_count) body += `<button class="cc-thr" data-act="thread"><span class="avs">${(m.thread_users || []).slice(0, 3).map(u => avatar(u, 20, false)).join('')}</span>${m.thread_count} ${m.thread_count === 1 ? 'respuesta' : 'respuestas'}<small>Última ${esc(shortWhen(m.thread_last_at))}</small></button>`;
    }
    const state = mine && !m.deleted && !inThread ? stateHtml(m, C) : (m.failed || m.pending ? stateHtml(m, C) : '');
    const canAct = !m.pending && !m.failed && !m.deleted && conv;
    const canDel = canAct && (mine || (C && C.canManage));
    const canPost = C ? C.canPost : !!conv;
    const acts = canAct ? `<div class="cc-acts" role="toolbar" aria-label="Acciones del mensaje">
        ${['👍', '❤️', '😂'].map(e => `<button class="q" data-react="${e}" title="Reaccionar ${e}">${e}</button>`).join('')}
        <button data-act="react-more" title="Más reacciones" aria-label="Más reacciones"><i class="fa-regular fa-face-smile"></i></button>
        ${!inThread && canPost && !m.parent_id ? `<button data-act="thread" title="Responder en hilo" aria-label="Responder en hilo"><i class="fa-regular fa-comments"></i></button>` : ''}
        ${!inThread && canPost ? `<button data-act="reply" title="Responder citando" aria-label="Responder citando"><i class="fa-solid fa-reply"></i></button>` : ''}
        ${mine && m.kind !== 'poll' ? `<button data-act="edit" title="Editar" aria-label="Editar"><i class="fa-solid fa-pen"></i></button>` : ''}
        <button data-act="more" title="Más acciones" aria-label="Más acciones"><i class="fa-solid fa-ellipsis-vertical"></i></button>
      </div>` : '';
    const isNew = !inThread && C && C.firstUnread === m.id ? '<div class="cc-new" id="cc-newline">Nuevos</div>' : '';
    const cls = `cc-m${m.pending ? ' pending' : ''}${m.mentions_me ? ' me-mention' : ''}${m.pinned ? ' pinned' : ''}`;
    const idp = inThread ? 't' : 'm';
    const mark = m.saved ? '<i class="fa-solid fa-bookmark cc-savedmark" title="Guardado"></i>' : '';
    if (cont) return `${isNew}<div class="${cls}" id="${idp}-${m.id}" data-id="${m.id}"><span class="cc-gut">${hhmm(m.created_at)}</span><div>${body}${state}</div>${acts}</div>`;
    return `${isNew}<div class="${cls} first" id="${idp}-${m.id}" data-id="${m.id}">${avatar(m.user_id, 40, false)}<div><div class="cc-mhd"><b data-user="${m.user_id}">${esc(uname(m.user_id))}</b><time title="${esc(fullWhen(m.created_at))}">${hhmm(m.created_at)}</time>${mark}</div>${body}${state}</div>${acts}</div>`;
  }
  function pollHtml(m) {
    const p = m.poll, tot = p.counts.reduce((a, b) => a + b, 0) || 0;
    const member = !!S.convs[m.conv_id];
    const canClose = m.user_id === S.me || (S.conv[m.conv_id] && S.conv[m.conv_id].canManage);
    return `<div class="cc-poll${p.closed ? ' closed' : ''}"><div class="q"><i class="fa-solid fa-square-poll-vertical"></i> ${esc(p.question)}</div>
      <small>${p.multi ? 'Puedes elegir varias opciones' : 'Elige una opción'}${p.anonymous ? ' · votación anónima' : ''}${p.closed ? ' · cerrada' : ''}</small>
      ${p.options.map((o, i) => { const n = p.counts[i] || 0, pct = tot ? Math.round(n * 100 / tot) : 0, on = p.mine.includes(i);
        return `<button class="cc-popt${on ? ' on' : ''}" data-pollopt="${i}" ${p.closed || !member ? 'disabled' : ''} title="${esc(p.anonymous ? '' : (p.who[i] || []).map(uname).join(', '))}"><span class="bar" style="width:${pct}%"></span><span class="t"><i class="fa-${on ? 'solid' : 'regular'} fa-${p.multi ? 'square-check' : 'circle-dot'}"></i>${esc(o)}</span><span class="n">${n} · ${pct}%</span></button>`; }).join('')}
      <div class="pf"><span>${p.voters} ${p.voters === 1 ? 'persona votó' : 'personas votaron'}</span>${canClose ? `<button class="cc-btn sm" data-act="poll-close">${p.closed ? 'Reabrir' : 'Cerrar encuesta'}</button>` : ''}</div></div>`;
  }
  function fileHtml(f) {
    if (/^image\//.test(f.mime)) return `<button class="cc-img" data-img="${esc(f.url)}" data-name="${esc(f.name)}" aria-label="Ver ${esc(f.name)}"><img src="${esc(f.url)}" alt="${esc(f.name)}" loading="lazy"></button>`;
    if (/^video\//.test(f.mime)) return `<video class="cc-vid" data-fk="${+f.id}" src="${esc(f.url)}" controls preload="metadata"></video>`;
    if (/^audio\//.test(f.mime)) return `<audio data-fk="${+f.id}" src="${esc(f.url)}" controls preload="metadata" style="max-width:320px"></audio>`;
    return `<a class="cc-file" href="${esc(f.url)}${f.url.includes('?') ? '&' : '?'}dl=1" target="_blank" rel="noopener"><span class="fi"><i class="fa-solid ${fileIcon(f.mime, f.name)}"></i></span><span style="min-width:0"><b>${esc(f.name)}</b><small>${fmtSize(f.size || 0)} · Descargar</small></span></a>`;
  }
  function stateHtml(m, C) {
    if (m.failed && m.queued && S.failN >= 2) return `<div class="cc-state"><i class="fa-regular fa-clock"></i> Se manda cuando vuelva la conexión · <button class="cc-state-x" data-act="discard" style="border:0;background:none;color:inherit;text-decoration:underline;padding:0;font-size:10.5px">Descartar</button></div>`;
    if (m.failed) return `<div class="cc-state fail"><i class="fa-solid fa-circle-exclamation"></i> No se mandó. <button data-act="retry">Reintentar</button> · <button data-act="discard">Descartar</button></div>`;
    if (m.pending) return `<div class="cc-state"><i class="fa-regular fa-clock"></i> Enviando…</div>`;
    const c = curConv(); if (!c || !C) return '';
    const last = [...C.list].reverse().find(x => x.user_id === S.me && !x.deleted && !x.pending && x.kind !== 'system');
    if (!last || last.id !== m.id) return '';
    const reads = (S.reads[c.id] || []).filter(r => r.user_id !== S.me);
    const seen = reads.filter(r => r.last_read_id >= m.id);
    if (c.kind === 'dm') return `<div class="cc-state${seen.length ? ' read' : ''}">${seen.length ? '<i class="fa-solid fa-check-double"></i> Visto' : '<i class="fa-solid fa-check"></i> Enviado'}</div>`;
    if (!reads.length) return '';
    return `<div class="cc-state${seen.length === reads.length ? ' read' : ''}" title="${esc(seen.map(r => uname(r.user_id)).join(', '))}"><i class="fa-solid fa-check${seen.length ? '-double' : ''}"></i> ${seen.length === reads.length ? 'Visto por todos' : seen.length ? `Visto por ${seen.length}` : 'Enviado'}</div>`;
  }
  /** Redibuja un contenedor conservando sus audios y videos: si no, cada "visto" o "escribiendo" que llega reinicia la reproducción. */
  function keepMedia(box, draw, root = box) {
    const old = new Map();
    if (box) box.querySelectorAll('audio[data-fk],video[data-fk]').forEach(el => old.set(el.dataset.fk + '|' + el.getAttribute('src'), el));
    draw();
    if (old.size && root) root.querySelectorAll('audio[data-fk],video[data-fk]').forEach(el => { const o = old.get(el.dataset.fk + '|' + el.getAttribute('src')); if (o && o !== el) { el.replaceWith(o); old.delete(el.dataset.fk + '|' + el.getAttribute('src')); } });
  }
  function renderMsgs() {
    const box = $('cc-msgs'), c = curConv(); if (!box || !c) return;
    const C = S.conv[c.id];
    if (!C) { box.innerHTML = '<div style="padding:20px 22px">' + '<div class="cc-sk" style="height:52px;margin:12px 0"></div>'.repeat(6) + '</div>'; return; }
    const wasBottom = atBottom(), oldH = box.scrollHeight, oldT = box.scrollTop;
    const editingText = S.editing && $('cc-edit') ? $('cc-edit').value : null;
    let html = '';
    if (!C.moreBefore) html += introHtml(c);
    else html += `<div class="cc-more"><button class="cc-btn sm" data-act="older"><i class="fa-solid fa-clock-rotate-left"></i> Ver mensajes anteriores</button></div>`;
    let prev = null;
    for (const m of C.list) {
      if (!prev || !sameDay(prev.created_at, m.created_at)) { html += `<div class="cc-day">${esc(dayLabel(m.created_at))}</div>`; prev = null; }
      html += msgHtml(m, prev, C); prev = m;
    }
    if (C.moreAfter) html += `<div class="cc-more"><button class="cc-btn sm" data-act="newer"><i class="fa-solid fa-arrow-down"></i> Ver mensajes más nuevos</button></div>`;
    keepMedia(box, () => { box.innerHTML = html; });
    if (editingText !== null && $('cc-edit')) { const t = $('cc-edit'); t.value = editingText; t.focus(); }
    if (S.keepAnchor) { box.scrollTop = box.scrollHeight - S.keepAnchor; S.keepAnchor = null; }
    else if (wasBottom) box.scrollTop = box.scrollHeight;
    else box.scrollTop = oldT + 0 * oldH;
    updateJump();
    if (S.aside === 'thread') renderThread();
    emit('msgs');
  }
  // Avisos para chat-plus.js (inicio, efectos, rompehielos): se dibujó algo
  const emit = what => { try { document.dispatchEvent(new CustomEvent('cc:render', { detail: what })); } catch (e) {} };
  function introHtml(c) {
    if (c.kind === 'dm') { const u = S.users[c.peer] || {}; return `<div class="cc-intro">${avatar(c.peer, 56)}<h3>${esc(convName(c))}</h3><p>${c.peer === S.me ? 'Tu espacio personal: anota cosas, guarda enlaces o prueba mensajes.' : `Este es el comienzo de tu conversación con ${esc(u.name || '')}.`}${u.position ? '<br>' + esc(u.position) : ''}</p></div>`; }
    return `<div class="cc-intro">${convIcon(c, 56)}<h3>${c.kind === 'channel' ? '#' : ''}${esc(c.name)}</h3><p>${c.topic ? esc(c.topic) + '<br>' : ''}${c.kind === 'channel' ? 'Este es el comienzo del canal.' : 'Este es el comienzo del grupo.'}</p></div>`;
  }
  const atBottom = () => { const b = $('cc-msgs'); return !b || b.scrollHeight - b.scrollTop - b.clientHeight < 80; };
  function updateJump() {
    const j = $('cc-jump'); if (!j) return;
    const show = !atBottom() && (S.newBelow > 0 || ($('cc-msgs').scrollHeight - $('cc-msgs').scrollTop - $('cc-msgs').clientHeight > 600));
    j.classList.toggle('on', show);
    $('cc-jumpn').textContent = S.newBelow || ''; $('cc-jumpn').hidden = !S.newBelow;
  }
  let olderBusy = false;
  function onScroll() {
    const b = $('cc-msgs');
    if (atBottom()) { S.newBelow = 0; markRead(); }
    updateJump();
    if (b.scrollTop < 120) loadOlder();
  }
  async function loadOlder() {
    const c = curConv(), C = c && S.conv[c.id];
    if (!C || !C.moreBefore || olderBusy) return;
    olderBusy = true;
    const first = C.list.find(m => !m.pending);
    try {
      const d = await api('history', { conv: c.id, before: first ? first.id : 0 });
      if (S.open !== c.id) return;
      const box = $('cc-msgs'); S.keepAnchor = box.scrollHeight - box.scrollTop;
      C.list = d.messages.concat(C.list); C.moreBefore = d.more_before;
      renderMsgs();
    } catch (e) { fail(e); } finally { olderBusy = false; }
  }
  async function loadNewer() {
    const c = curConv(), C = c && S.conv[c.id]; if (!C) return;
    const last = [...C.list].reverse().find(m => !m.pending);
    try { const d = await api('history', { conv: c.id, after: last ? last.id : 0 }); C.list = C.list.concat(d.messages); C.moreAfter = d.more_after; renderMsgs(); } catch (e) { fail(e); }
  }
  let readT = null;
  function markRead() {
    const c = curConv(); if (!c || !S.convs[c.id] || document.hidden || !document.hasFocus()) return;
    const C = S.conv[c.id]; if (!C || C.moreAfter || !atBottom()) return;
    const last = [...C.list].reverse().find(m => !m.pending && typeof m.id === 'number');
    if (!last) return;
    const conv = S.convs[c.id];
    if (conv.last_read_id >= last.id && !conv.unread) return;
    conv.last_read_id = last.id; conv.unread = 0; renderList(); updateBadges();
    clearTimeout(readT); readT = setTimeout(() => api('read', { conv: c.id, last_id: last.id }).catch(() => {}), 300);
  }

  function renderTyping() {
    const t = $('cc-typing'); if (!t) return;
    const names = S.typing.filter(id => id !== S.me).map(id => uname(id).split(' ')[0]);
    t.innerHTML = names.length ? `<span class="dots"><i></i><i></i><i></i></span> ${esc(names.length === 1 ? names[0] + ' está escribiendo…' : names.length === 2 ? names.join(' y ') + ' están escribiendo…' : 'Varias personas están escribiendo…')}` : '';
  }

  // ── Escribir ──
  function renderComposer() {
    const c = curConv(), box = $('cc-comp'); if (!box) return;
    const C = S.conv[c.id];
    if (!S.convs[c.id]) {
      box.innerHTML = C && C.canJoin === false && !C.member ? `<div class="cc-locked"><i class="fa-solid fa-lock"></i> No puedes escribir en este canal</div>`
        : `<div class="cc-locked"><span>Estás viendo <b>#${esc(c.name)}</b>.</span><button class="cc-btn pri sm" data-join="${c.id}">Unirme al canal</button></div>`;
      return;
    }
    if (C && !C.canPost) { box.innerHTML = `<div class="cc-locked"><i class="fa-solid fa-bullhorn"></i> En este canal solo publican sus administradores.</div>`; return; }
    const ph = c.kind === 'channel' ? `Escribir en #${c.name}` : `Escribir a ${convName(c)}`;
    box.innerHTML = `
      <div class="cc-typing" id="cc-typing" aria-live="polite"></div>
      <div class="cc-box" id="cc-box">
        <div id="cc-replybar"></div>
        <div class="cc-att" id="cc-att" hidden></div>
        <div class="cc-fmtbar" id="cc-fmtbar" ${LS.get('fmtbar', false) ? '' : 'hidden'} role="toolbar" aria-label="Formato">
          ${[['b', 'fa-bold', 'Negrita (Ctrl+B)'], ['i', 'fa-italic', 'Cursiva (Ctrl+I)'], ['u', 'fa-underline', 'Subrayado'], ['s', 'fa-strikethrough', 'Tachado'], ['link', 'fa-link', 'Enlace'], ['ul', 'fa-list-ul', 'Lista'], ['ol', 'fa-list-ol', 'Lista numerada'], ['q', 'fa-quote-left', 'Cita'], ['code', 'fa-code', 'Código'], ['pre', 'fa-file-code', 'Bloque de código']].map(([k, ic, l]) => `<button type="button" data-fmt="${k}" title="${l}" aria-label="${l}"><i class="fa-solid ${ic}"></i></button>`).join('')}
        </div>
        <div class="cc-input">
          <button class="cc-ib" data-act="comp-plus" title="Encuesta, programar, recordatorio${C && C.canManage && c.kind === 'channel' ? ', anuncio' : ''}" aria-label="Más opciones"><i class="fa-solid fa-plus"></i></button>
          <button class="cc-ib cc-qa-btn" data-act="comp-quick" title="Acciones rápidas (o escribe /)" aria-label="Acciones rápidas"><i class="fa-solid fa-bolt"></i></button>
          <button class="cc-ib" data-act="attach" title="Adjuntar archivos" aria-label="Adjuntar archivos"><i class="fa-solid fa-paperclip"></i></button>
          <textarea id="cc-ta" rows="1" placeholder="${esc(ph)}" aria-label="${esc(ph)}"></textarea>
          <button class="cc-ib${LS.get('fmtbar', false) ? ' on' : ''}" data-act="fmt-toggle" title="Formato" aria-label="Mostrar barra de formato"><i class="fa-solid fa-font"></i></button>
          <button class="cc-ib" data-act="emoji" title="Emojis" aria-label="Emojis"><i class="fa-regular fa-face-smile"></i></button>
          <button class="cc-ib" data-act="ref-link" data-refpk-open title="Vincular cliente, contenido, tarea o ticket" aria-label="Vincular cliente, contenido, tarea o ticket"><i class="fa-solid fa-link"></i></button>
          <button class="cc-send" id="cc-send" data-act="send" title="Enviar" aria-label="Enviar" disabled><i class="fa-solid fa-paper-plane"></i></button>
        </div>
      </div>
      <div class="cc-hint"><span><kbd>Enter</kbd> envía · <kbd>Shift+Enter</kbd> nueva línea</span><span><kbd>/</kbd> acciones · <kbd>@</kbd> menciona · **negrita** _cursiva_ ~tachado~ \`código\`</span></div>
      <input type="file" id="cc-file" multiple hidden>`;
    const ta = $('cc-ta');
    ta.value = S.draft || '';
    autosize(ta); renderReplyBar(); renderAtt(); renderTyping(); updateSend();
    if (matchMedia('(pointer:fine)').matches) ta.focus();
  }
  function autosize(ta) { ta.style.height = 'auto'; ta.style.height = Math.min(220, ta.scrollHeight) + 'px'; }
  function updateSend() {
    const b = $('cc-send'), ta = $('cc-ta'); if (!b || !ta) return;
    const busy = S.att.some(a => !a.file && !a.err);
    b.disabled = busy || (!ta.value.trim() && !S.att.some(a => a.file));
    b.title = busy ? 'Espera a que terminen de subir los archivos' : 'Enviar';
  }
  function renderReplyBar() {
    const r = $('cc-replybar'); if (!r) return;
    const m = S.replyTo;
    r.innerHTML = m ? `<div class="cc-replyto"><i class="fa-solid fa-reply"></i><span>Respondiendo a <b>${esc(uname(m.user_id))}</b>: ${esc((m.body || '📎 Archivo').slice(0, 120))}</span><button class="cc-ib" data-act="reply-cancel" aria-label="Cancelar respuesta" style="width:26px;height:26px"><i class="fa-solid fa-xmark"></i></button></div>` : '';
  }
  function renderAtt() {
    const a = $('cc-att'); if (!a) return;
    a.hidden = !S.att.length;
    a.innerHTML = S.att.map(x => `<div class="cc-chip${x.err ? ' err' : ''}">${x.preview ? `<img src="${x.preview}" alt="">` : `<span class="fi"><i class="fa-solid ${fileIcon(x.type, x.name)}"></i></span>`}<span><span style="display:block;max-width:150px;overflow:hidden;text-overflow:ellipsis">${esc(x.name)}</span><small>${x.err ? esc(x.err) : x.file ? fmtSize(x.size) : 'Subiendo… ' + Math.round(x.prog * 100) + '%'}</small></span><button data-rmatt="${x.tmp}" aria-label="Quitar ${esc(x.name)}"><i class="fa-solid fa-xmark"></i></button>${!x.file && !x.err ? `<span class="bar" style="width:${Math.round(x.prog * 100)}%"></span>` : ''}</div>`).join('');
    updateSend();
  }
  function addFiles(files) {
    const c = curConv(); if (!c || !S.convs[c.id]) return;
    [...files].slice(0, 10 - S.att.length).forEach(f => {
      const ext = (f.name.split('.').pop() || '').toLowerCase();
      const a = { tmp: newId(), name: f.name || 'imagen.png', size: f.size, type: f.type, prog: 0, file: null, err: null, preview: /^image\//.test(f.type) ? URL.createObjectURL(f) : null };
      if (!S.exts.includes(ext)) a.err = 'Tipo de archivo no permitido';
      else if (f.size > S.maxBytes) a.err = `Pesa más de ${Math.round(S.maxBytes / 1048576)} MB`;
      S.att.push(a);
      if (!a.err) upload(f, a, c.id);
    });
    renderAtt();
  }
  async function upload(f, a, conv, standalone) {
    const CH = 1.5 * 1024 * 1024, parts = Math.max(1, Math.ceil(f.size / CH)), token = hex32();
    try {
      for (let i = 0; i < parts; i++) {
        if (!standalone && !S.att.includes(a)) { api('up_abort', { token }).catch(() => {}); return; }
        const fd = new FormData(); fd.append('action', 'up_chunk'); fd.append('token', token); fd.append('index', i); fd.append('chunk', f.slice(i * CH, (i + 1) * CH), 'p');
        const r = await fetch(API, { method: 'POST', body: fd, headers: { 'X-CH': '1' } });
        const d = await r.json().catch(() => null);
        if (!d || !d.ok) throw new Error((d && d.error) || 'Se cortó la subida');
        a.prog = (i + 1) / parts; if (!standalone) renderAtt();
      }
      const d = await api('up_done', { token, parts, size: f.size, name: a.name, conv });
      a.file = d.file;
    } catch (e) { a.err = e.message; }
    if (!standalone) renderAtt();
  }

  let typingAt = 0;
  async function send() {
    const c = curConv(), ta = $('cc-ta'); if (!c || !ta) return;
    const body = ta.value.trim();
    const ready = S.att.filter(a => a.file);
    if (S.att.some(a => !a.file && !a.err)) return;
    if (!body && !ready.length) return;
    // Acciones rápidas: "/ticket …", "/tarea …", "/nota …" (sin adjuntos); un "/" desconocido se manda como texto
    const qa = !ready.length && !S.replyTo ? qaParse(body) : null;
    if (qa) { ta.value = ''; S.draft = ''; LS.set('draft.' + c.id, ''); autosize(ta); updateSend(); mentionClose(); qaRun(qa.a.cmd, qa.text); return; }
    const C = S.conv[c.id];
    const m = { id: 'p' + newId(), client_id: newId(), conv_id: c.id, user_id: S.me, kind: 'text', body, created_at: new Date(), pending: true, reply_to: S.replyTo ? { id: S.replyTo.id, user_id: S.replyTo.user_id, body: S.replyTo.body } : null, files: ready.map(a => a.file), reactions: [] };
    const payload = { conv: c.id, body, client_id: m.client_id, reply_to: S.replyTo ? S.replyTo.id : 0, files: ready.map(a => a.file.id) };
    m.payload = payload;
    if (C) { if (C.moreAfter) { await loadNewer(); } C.list.push(m); }
    ta.value = ''; S.draft = ''; LS.set('draft.' + c.id, ''); autosize(ta);
    S.att = []; S.replyTo = null; renderAtt(); renderReplyBar();
    renderMsgs(); $('cc-msgs').scrollTop = 1e9;
    deliver(m);
  }
  async function deliver(m) {
    const C = S.conv[m.conv_id];
    try {
      const d = await api('send', m.payload);
      beep('sent');
      if (C) { const i = C.list.indexOf(m); const j = C.list.findIndex(x => x.id === d.message.id); if (j >= 0 && j !== i) C.list.splice(i, 1); else if (i >= 0) C.list[i] = d.message; }
      if (/https?:\/\//.test(d.message.body || '')) api('unfurl', { id: d.message.id }).then(x => { if (x.message) { upsert(x.message); if (S.open === x.message.conv_id) renderMsgs(); } }).catch(() => {});
      const cv = S.convs[m.conv_id]; if (cv) { cv.last_msg_id = d.message.id; cv.last_msg_at = d.message.created_at; cv.last = { user_id: S.me, body: d.message.body, kind: 'text' }; cv.last_read_id = d.message.id; }
    } catch (e) {
      // Sin conexión: queda en cola y se reenvía sola al volver. Rechazo del servidor: se muestra el motivo.
      m.pending = false; m.failed = true; m.error = e.message; m.queued = !e.status;
      if (e.status && e.status !== 429) toast(e.message, true);
    }
    if (S.open === m.conv_id) renderMsgs();
    renderList();
  }
  function resendQueued() {
    Object.values(S.conv).forEach(C => C.list.filter(m => m.failed && m.queued).forEach(m => { m.failed = false; m.pending = true; deliver(m); }));
    if (S.open) renderMsgs();
  }
  function startEdit(id) { S.editing = id; renderMsgs(); const t = $('cc-edit'); if (t) { t.focus(); t.setSelectionRange(t.value.length, t.value.length); autosize(t); } }
  async function saveEdit() {
    const t = $('cc-edit'); if (!t) return;
    const id = S.editing, body = t.value.trim();
    if (!body) { const m = msgById(id); if (m && !(m.files || []).length) { S.editing = null; return askDelete(m); } }
    S.editing = null;
    try { const d = await api('edit', { id, body }); upsert(d.message); } catch (e) { fail(e); }
    renderMsgs(); $('cc-ta')?.focus();
  }
  const msgById = id => {
    const C = S.conv[S.open];
    return (C && C.list.find(m => m.id === id)) || (S.thread && (S.thread.root.id === id ? S.thread.root : S.thread.replies.find(m => m.id === id))) || (S.lookup && S.lookup[id]) || null;
  };
  async function askDelete(m) {
    if (!(await confirmDlg('¿Eliminar este mensaje?', 'Se va a ver «Se eliminó este mensaje» en su lugar. No se puede deshacer.', 'Eliminar', true))) return;
    try { const d = await api('delete', { id: m.id }); upsert(d.message); renderMsgs(); toast('Mensaje eliminado'); } catch (e) { fail(e); }
  }
  async function react(id, emoji) {
    const m = msgById(id); if (!m) return;
    // Al instante en pantalla; el servidor confirma
    const r = m.reactions.find(x => x.emoji === emoji);
    if (r && r.mine) { r.users = r.users.filter(u => u !== S.me); r.mine = false; if (!r.users.length) m.reactions = m.reactions.filter(x => x !== r); }
    else if (r) { r.users.push(S.me); r.mine = true; } else m.reactions.push({ emoji, users: [S.me], mine: true });
    renderMsgs();
    try { const d = await api('react', { id, emoji }); upsert(d.message); renderMsgs(); } catch (e) { fail(e); }
  }

  // ── Panel derecho: detalles, miembros y archivos ──
  async function loadInfo() {
    const c = curConv(); if (!c) return;
    try { const d = await api('info', { conv: c.id }); if (S.open !== c.id) return; S.info = d; renderAside(); } catch (e) { fail(e); }
  }
  function toggleAside(tab) {
    if (S.aside === 'info' && (!tab || tab === S.asideTab)) { S.aside = null; cc.classList.remove('aside-on'); renderHead(); return; }
    S.thread = null;
    S.aside = 'info'; if (tab) S.asideTab = tab; cc.classList.add('aside-on'); S.info = null; renderAside(); renderHead(); loadInfo();
  }
  function renderAside() {
    if (S.aside === 'thread') return renderThread();
    const c = curConv(); if (!c || !S.aside) return;
    const I = S.info;
    const tabs = c.kind === 'dm' ? [['info', 'Perfil'], ['pins', 'Fijados'], ['files', 'Archivos']] : [['info', 'Detalles'], ['members', 'Miembros'], ['pins', 'Fijados'], ['files', 'Archivos']];
    if (!tabs.some(t => t[0] === S.asideTab)) S.asideTab = 'info';
    let body = '';
    if (!I) body = '<div class="cc-sk"></div>'.repeat(5);
    else if (S.asideTab === 'pins') body = I.pins.length ? I.pins.map(m => { (S.lookup ||= {})[m.id] = m; return `<div class="cc-card2" data-gomsg="${m.id}"><div class="h">${avatar(m.user_id, 22, false)}<b>${esc(uname(m.user_id))}</b><span>${esc(shortWhen(m.created_at))}</span></div><div class="b">${esc((m.body || (m.poll ? '📊 ' + m.poll.question : m.ann ? m.ann.title : '📎 Archivo')).slice(0, 240))}</div></div>`; }).join('') : '<div class="cc-empty"><i class="fa-solid fa-thumbtack big"></i>No hay mensajes fijados. Fija los importantes desde el menú <i class="fa-solid fa-ellipsis-vertical"></i> de cada mensaje.</div>';
    else if (S.asideTab === 'files') body = I.files.length ? I.files.map(f => `<a class="cc-afile" href="${esc(f.url)}" target="_blank" rel="noopener"><span class="fi">${/^image\//.test(f.mime) ? `<img src="${esc(f.url)}" alt="" loading="lazy">` : `<i class="fa-solid ${fileIcon(f.mime, f.name)}"></i>`}</span><span style="flex:1;min-width:0"><b>${esc(f.name)}</b><small>${fmtSize(f.size)} · ${esc(uname(f.user_id).split(' ')[0])} · ${shortWhen(f.created_at)}</small></span></a>`).join('') : '<div class="cc-empty"><i class="fa-regular fa-folder-open big"></i>Todavía no se compartieron archivos acá.</div>';
    else if (S.asideTab === 'members') {
      const canAdd = I.can_manage || (c.kind === 'channel' && c.visibility === 'public' && S.convs[c.id]);
      body = `${canAdd ? `<button class="cc-btn" style="width:100%;margin-bottom:10px" data-act="add-members"><i class="fa-solid fa-user-plus"></i> Agregar personas</button>` : ''}` +
        I.members.map(m => { const u = S.users[m.user_id] || {}; return `<div class="cc-mem">${avatar(m.user_id, 34)}<span class="cc-rt"><b data-user="${m.user_id}">${esc(u.name || 'Usuario')}${m.user_id === S.me ? ' (tú)' : ''}</b><small>${esc(u.position || u.dept || statusLabel(u))}</small></span>${m.role !== 'member' ? `<span class="cc-tag">${m.role === 'owner' ? 'Creador' : 'Admin'}</span>` : ''}${(I.can_manage && m.user_id !== S.me && m.role !== 'owner') ? `<button class="cc-ib" data-memmenu="${m.user_id}" data-role="${m.role}" aria-label="Opciones de ${esc(u.name || '')}"><i class="fa-solid fa-ellipsis"></i></button>` : ''}</div>`; }).join('');
    } else if (c.kind === 'dm') {
      const u = S.users[c.peer] || {};
      body = `<div style="text-align:center;padding:6px 0 10px">${avatar(c.peer, 88)}<h3 style="margin:12px 0 2px;font-size:18px">${esc(u.name || '')}</h3><div style="color:var(--g7-text-3);font-size:13px">${esc(u.position || '')}${u.dept ? ' · ' + esc(u.dept) : ''}</div><div style="margin-top:8px;font-size:12.5px;color:var(--g7-text-2)"><i class="cc-st ${u.online ? u.status : 'off'}" style="display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px"></i>${esc(statusLabel(u))}</div></div>
        <div class="cc-wkbox" data-wk="${c.peer}"></div><h4>Contacto</h4><div class="cc-prefs">${u.email ? `<a class="cc-toggle" href="mailto:${esc(u.email)}" style="text-decoration:none;color:inherit"><i class="fa-regular fa-envelope"></i>${esc(u.email)}</a>` : '<span class="cc-empty" style="padding:4px;text-align:left">Sin datos de contacto</span>'}</div>` + prefsHtml(c, I);
    } else body = infoForm(c, I) + prefsHtml(c, I);
    aside.innerHTML = `<div class="cc-ah"><h3>${c.kind === 'dm' ? 'Conversación' : c.kind === 'channel' ? 'Canal' : 'Grupo'}</h3><button class="cc-ib" data-act="aside-close" aria-label="Cerrar detalles"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-ab"><div class="cc-tabs" role="tablist">${tabs.map(([k, l]) => `<button role="tab" aria-selected="${S.asideTab === k}" class="${S.asideTab === k ? 'on' : ''}" data-tab="${k}">${l}${k === 'members' && I ? ` · ${I.members.length}` : ''}</button>`).join('')}</div>${body}</div>`;
    aside.querySelectorAll('[data-wk]').forEach(fillWork);
  }
  // En qué trabaja la persona y sus tareas en curso (trabajo/user_work_lib.php vía user_work). Caché de 30 s: el panel se redibuja seguido
  const WK = {};
  function fillWork(el) {
    if (!el || !window.CCRefs) return;
    const uid = +el.dataset.wk, u = S.users[uid] || {}, name = (u.name || '').split(' ')[0] || 'Esta persona';
    const paint = w => {
      if (!el.isConnected) return;
      el.innerHTML = w ? `<h4>Trabajo</h4>${CCRefs.work(w, uid === S.me ? 'Tú' : name)}` : '';
      const card = el.closest('.cc-card');   // la tarjeta flotante creció: que no se salga de la pantalla
      if (card) card.style.top = Math.max(8, Math.min(card.offsetTop, innerHeight - card.offsetHeight - 8)) + 'px';
    };
    const c = WK[uid]; if (c && Date.now() - c.t < 30000) return paint(c.w);
    api('user_work', { user: uid }).then(d => { WK[uid] = { t: Date.now(), w: d.work }; paint(d.work); }).catch(() => {});
  }
  function infoForm(c, I) {
    const m = I.can_manage;
    if (!m) return `${convIcon(c, 64)}<h3 style="margin:12px 0 4px">${c.kind === 'channel' ? '#' : ''}${esc(c.name)}</h3><p style="color:var(--g7-text-3);margin:0 0 6px;line-height:1.5">${esc(c.topic || 'Sin descripción')}</p>${c.kind === 'channel' ? `<p style="font-size:12px;color:var(--g7-text-4)">${{ public: 'Canal público', private: 'Canal privado', restricted: 'Canal restringido a algunos equipos' }[c.visibility]}${c.announce ? ' · solo publican los administradores' : ''}</p>` : ''}`;
    const ch = c.kind === 'channel';
    return `<form id="cc-infoform">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">${convIcon(c, 56)}<span style="display:flex;flex-direction:column;gap:5px"><button type="button" class="cc-btn sm" data-act="avatar"><i class="fa-solid fa-image"></i> Cambiar imagen</button>${c.avatar ? '<button type="button" class="cc-btn sm" data-act="avatar-rm">Quitar</button>' : ''}</span></div>
      <div class="cc-fld"><label for="cc-f-name">Nombre</label><input class="cc-in" id="cc-f-name" value="${esc(c.name)}" maxlength="80"></div>
      <div class="cc-fld"><label for="cc-f-topic">Descripción</label><textarea class="cc-in" id="cc-f-topic" maxlength="300" placeholder="¿De qué se habla acá?">${esc(c.topic || '')}</textarea></div>
      ${ch ? `<div class="cc-fld"><label for="cc-f-cat">Categoría</label><select class="cc-in" id="cc-f-cat"><option value="0">Sin categoría</option>${S.cats.map(k => `<option value="${k.id}" ${+k.id === c.category_id ? 'selected' : ''}>${esc(k.name)}</option>`).join('')}</select></div>
      <div class="cc-fld"><label>Quién puede entrar</label>${visRadios(c.visibility, 'cc-f-vis')}</div>
      <div class="cc-fld" id="cc-f-teams-w" ${c.visibility === 'restricted' ? '' : 'hidden'}><label>Equipos con acceso</label>${teamChecks(c.allowed_teams || [], 'cc-f-team')}</div>
      <label class="cc-check"><input type="checkbox" id="cc-f-ann" ${c.announce ? 'checked' : ''}> Canal de anuncios: solo publican los administradores</label>` : ''}
      <button class="cc-btn pri" style="margin-top:8px" type="submit">Guardar cambios</button>
    </form>`;
  }
  function prefsHtml(c, I) {
    if (!S.convs[c.id]) return '';
    const ownerOrAdmin = I && (I.is_owner || (c.kind === 'channel' && S.admin));
    return `<h4>Preferencias</h4><div class="cc-prefs">
      <button class="cc-toggle" data-act="fav"><i class="fa-${c.favorite ? 'solid' : 'regular'} fa-star"></i>${c.favorite ? 'Quitar de favoritos' : 'Marcar como favorito'}</button>
      <button class="cc-toggle" data-act="mute"><i class="fa-solid ${c.muted ? 'fa-bell' : 'fa-bell-slash'}"></i>${c.muted ? 'Volver a avisarme' : 'Silenciar'}</button>
      <label class="cc-toggle" style="cursor:default"><i class="fa-regular fa-bell"></i><span style="flex:1">Avisarme de</span><select class="cc-in" data-notify-level style="width:auto;height:32px"><option value="all" ${c.notify === 'all' ? 'selected' : ''}>Todos los mensajes</option><option value="mentions" ${c.notify === 'mentions' ? 'selected' : ''}>Solo menciones</option><option value="none" ${c.notify === 'none' ? 'selected' : ''}>Nada</option></select></label>
      ${c.kind !== 'dm' ? `<button class="cc-toggle danger" data-act="leave"><i class="fa-solid fa-arrow-right-from-bracket"></i>${c.kind === 'channel' ? 'Salir del canal' : 'Salir del grupo'}</button>` : ''}
      ${c.kind !== 'dm' && ownerOrAdmin ? `<button class="cc-toggle danger" data-act="conv-delete"><i class="fa-regular fa-trash-can"></i>${c.kind === 'channel' ? 'Borrar canal' : 'Borrar grupo'}</button>` : ''}
    </div>`;
  }
  const visRadios = (v, name) => `<div class="cc-radio">${[['public', 'Público', 'Cualquiera de la empresa lo encuentra y se une'], ['private', 'Privado', 'Solo entra quien es invitado'], ['restricted', 'Restringido', 'Solo pueden entrar los equipos que elijas']].map(([k, l, d]) => `<label><input type="radio" name="${name}" value="${k}" ${v === k ? 'checked' : ''}><span><b>${l}</b><small>${d}</small></span></label>`).join('')}</div>`;
  const teamChecks = (sel, name) => S.teams.length ? `<div class="cc-pick" style="max-height:170px">${S.teams.map(t => `<label><input type="checkbox" name="${name}" value="${t.id}" ${sel.includes(+t.id) ? 'checked' : ''}><span class="cc-rt"><b>${esc(t.name)}</b></span></label>`).join('')}</div>` : '<p class="sub" style="font-size:12px;color:var(--g7-text-4)">No hay equipos creados en Admin.</p>';

  // ── Ventanas ──
  function dialog(html, onReady) {
    const ov = document.createElement('div'); ov.className = 'cc-ov';
    ov.innerHTML = `<div class="cc-dlg" role="dialog" aria-modal="true">${html}</div>`;
    document.body.appendChild(ov);
    const close = () => { ov.remove(); document.removeEventListener('keydown', esc_); };
    const esc_ = e => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', esc_);
    ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
    ov.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', close));
    const first = ov.querySelector('input:not([type=checkbox]):not([type=radio]),textarea,select,button.pri'); if (first) setTimeout(() => first.focus(), 30);
    onReady && onReady(ov, close);
    return { ov, close };
  }
  function confirmDlg(title, text, ok = 'Confirmar', danger = false) {
    return new Promise(res => {
      dialog(`<div class="cc-dh"><h3>${esc(title)}</h3></div><div class="cc-db"><p class="sub">${esc(text)}</p></div><div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn ${danger ? 'red' : 'pri'}" data-ok>${esc(ok)}</button></div>`, (ov, close) => {
        let done = false;
        ov.querySelector('[data-ok]').addEventListener('click', () => { done = true; close(); res(true); });
        new MutationObserver((_, o) => { if (!document.body.contains(ov)) { o.disconnect(); if (!done) res(false); } }).observe(document.body, { childList: true });
        setTimeout(() => ov.querySelector('[data-ok]').focus(), 30);
      });
    });
  }
  function peoplePicker(ov, exclude = [], single = false) {
    const box = ov.querySelector('.cc-pick'), chips = ov.querySelector('.cc-chips'), q = ov.querySelector('[data-pq]');
    const chosen = new Set();
    const draw = () => {
      const t = (q.value || '').toLowerCase();
      const ppl = Object.values(S.users).filter(u => u.active && !exclude.includes(u.id) && (!t || u.name.toLowerCase().includes(t) || (u.dept || '').toLowerCase().includes(t))).sort((a, b) => a.name.localeCompare(b.name));
      box.innerHTML = ppl.map(u => `<label><input type="${single ? 'radio' : 'checkbox'}" name="pp" value="${u.id}" ${chosen.has(u.id) ? 'checked' : ''}>${avatar(u.id, 30)}<span class="cc-rt"><b>${esc(u.name)}${u.id === S.me ? ' (tú)' : ''}</b><small>${esc([u.position, u.dept].filter(Boolean).join(' · ') || statusLabel(u))}</small></span></label>`).join('') || '<div class="cc-empty">Nadie coincide</div>';
      if (chips) chips.innerHTML = [...chosen].map(id => `<span>${esc(uname(id))}<button type="button" data-unpick="${id}" aria-label="Quitar">✕</button></span>`).join('');
    };
    box.addEventListener('change', e => { const id = +e.target.value; if (single) chosen.clear(); e.target.checked ? chosen.add(id) : chosen.delete(id); draw(); ov.dispatchEvent(new Event('picked')); });
    chips && chips.addEventListener('click', e => { const b = e.target.closest('[data-unpick]'); if (b) { chosen.delete(+b.dataset.unpick); draw(); ov.dispatchEvent(new Event('picked')); } });
    q.addEventListener('input', draw);
    draw();
    return chosen;
  }
  function newDM() {
    dialog(`<div class="cc-dh"><h3>Nuevo mensaje</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><p class="sub">Elige una persona para escribirle. Si eliges varias, se arma un grupo.</p><input class="cc-in" data-pq placeholder="Buscar por nombre o equipo" aria-label="Buscar persona"><div class="cc-chips"></div><div class="cc-pick"></div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go disabled>Escribir</button></div>`, (ov, close) => {
      const chosen = peoplePicker(ov, []);
      const go = ov.querySelector('[data-go]');
      ov.addEventListener('picked', () => { go.disabled = !chosen.size; go.textContent = chosen.size > 1 ? 'Crear grupo' : 'Escribir'; });
      go.addEventListener('click', () => { close(); const ids = [...chosen]; if (ids.length === 1) openDM(ids[0]); else newGroup(ids); });
    });
  }
  function newGroup(pre = []) {
    dialog(`<div class="cc-dh"><h3>Nuevo grupo</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><div class="cc-fld"><label for="cc-g-name">Nombre del grupo</label><input class="cc-in" id="cc-g-name" maxlength="80" placeholder="Ej: Lanzamiento de noviembre"></div>
      <div class="cc-fld"><label>Participantes</label><input class="cc-in" data-pq placeholder="Buscar por nombre o equipo" aria-label="Buscar persona"><div class="cc-chips"></div><div class="cc-pick"></div></div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go>Crear grupo</button></div>`, (ov, close) => {
      const chosen = peoplePicker(ov, [S.me]);
      pre.filter(id => id !== S.me).forEach(id => { const i = ov.querySelector(`input[name=pp][value="${id}"]`); if (i) { i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); } });
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        const name = $('cc-g-name').value.trim();
        if (!name) { $('cc-g-name').focus(); return toast('Pon un nombre para el grupo', true); }
        if (!chosen.size) return toast('Suma al menos una persona', true);
        e.target.disabled = true;
        try { const d = await api('group_create', { name, members: [...chosen] }); S.convs[d.conv.id] = d.conv; close(); openConv(d.conv.id); toast('Grupo creado'); } catch (err) { fail(err); e.target.disabled = false; }
      });
    });
  }
  function newChannel() {
    dialog(`<div class="cc-dh"><h3>Crear canal</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><p class="sub">Los canales ordenan las conversaciones por tema, proyecto o área.</p>
      <div class="cc-fld"><label for="cc-c-name">Nombre</label><input class="cc-in" id="cc-c-name" maxlength="60" placeholder="ej: diseño, lanzamientos, soporte"></div>
      <div class="cc-fld"><label for="cc-c-topic">Descripción <span style="text-transform:none;font-weight:400">(opcional)</span></label><input class="cc-in" id="cc-c-topic" maxlength="300" placeholder="¿De qué se habla en este canal?"></div>
      <div class="cc-fld"><label for="cc-c-cat">Categoría</label><select class="cc-in" id="cc-c-cat"><option value="0">Sin categoría</option>${S.cats.map(k => `<option value="${k.id}">${esc(k.name)}</option>`).join('')}</select></div>
      <div class="cc-fld"><label>Quién puede entrar</label>${visRadios('public', 'cc-c-vis')}</div>
      <div class="cc-fld" id="cc-c-teams-w" hidden><label>Equipos con acceso</label>${teamChecks([], 'cc-c-team')}</div>
      <label class="cc-check"><input type="checkbox" id="cc-c-ann"> Canal de anuncios: solo publican los administradores</label>
      <div class="cc-fld" style="margin-top:10px"><label>Invitar personas <span style="text-transform:none;font-weight:400">(opcional)</span></label><input class="cc-in" data-pq placeholder="Buscar por nombre o equipo" aria-label="Buscar persona"><div class="cc-chips"></div><div class="cc-pick" style="max-height:180px"></div></div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go>Crear canal</button></div>`, (ov, close) => {
      const chosen = peoplePicker(ov, [S.me]);
      const nm = $('cc-c-name');
      nm.addEventListener('input', () => { const p = nm.selectionStart; nm.value = nm.value.toLowerCase().replace(/\s+/g, '-'); nm.setSelectionRange(p, p); });
      ov.addEventListener('change', e => { if (e.target.name === 'cc-c-vis') $('cc-c-teams-w').hidden = e.target.value !== 'restricted'; });
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        const name = nm.value.trim(); if (!name) { nm.focus(); return toast('Pon un nombre para el canal', true); }
        const vis = ov.querySelector('input[name=cc-c-vis]:checked').value;
        const teams = [...ov.querySelectorAll('input[name=cc-c-team]:checked')].map(i => +i.value);
        if (vis === 'restricted' && !teams.length) return toast('Elige qué equipos pueden entrar', true);
        e.target.disabled = true;
        try {
          const d = await api('channel_create', { name, topic: $('cc-c-topic').value, category_id: +$('cc-c-cat').value, visibility: vis, allowed_teams: teams, announce: $('cc-c-ann').checked, members: [...chosen] });
          S.convs[d.conv.id] = d.conv; S.browse = null; close(); S.view = 'home'; setRail(); openConv(d.conv.id); toast('Canal creado');
        } catch (err) { fail(err); e.target.disabled = false; }
      });
    });
  }
  function addMembers() {
    const c = curConv(), I = S.info; if (!c || !I) return;
    dialog(`<div class="cc-dh"><h3>Agregar personas</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><input class="cc-in" data-pq placeholder="Buscar por nombre o equipo" aria-label="Buscar persona"><div class="cc-chips"></div><div class="cc-pick"></div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go disabled>Agregar</button></div>`, (ov, close) => {
      const chosen = peoplePicker(ov, I.members.map(m => m.user_id));
      const go = ov.querySelector('[data-go]');
      ov.addEventListener('picked', () => { go.disabled = !chosen.size; });
      go.addEventListener('click', async () => {
        go.disabled = true;
        try { const d = await api('members_add', { conv: c.id, user_ids: [...chosen] }); close(); toast(d.added.length === 1 ? 'Se sumó 1 persona' : `Se sumaron ${d.added.length} personas`); loadInfo(); } catch (e) { fail(e); go.disabled = false; }
      });
    });
  }

  // ── Menús flotantes ──
  let menuEl = null;
  function closeMenus() { menuEl && menuEl.remove(); menuEl = null; document.querySelectorAll('.cc-emo,.cc-card').forEach(e => e.remove()); document.querySelectorAll('.cc-m.acts-on').forEach(e => e.classList.remove('acts-on')); }
  function place(el, x, y) {
    document.body.appendChild(el);
    const r = el.getBoundingClientRect();
    el.style.left = Math.max(8, Math.min(x, innerWidth - r.width - 8)) + 'px';
    el.style.top = Math.max(8, Math.min(y, innerHeight - r.height - 8)) + 'px';
  }
  function menu(items, x, y) {
    closeMenus();
    menuEl = document.createElement('div'); menuEl.className = 'cc-menu'; menuEl.setAttribute('role', 'menu');
    menuEl.innerHTML = items.map((it, i) => it === '-' ? '<hr>' : it.head ? `<div class="mh">${esc(it.head)}</div>` : `<button role="menuitem" data-i="${i}" class="${it.red ? 'red' : ''}${it.on ? ' on' : ''}">${it.icon ? `<i class="${it.icon}"></i>` : ''}${it.html || esc(it.label)}</button>`).join('');
    menuEl.addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (!b) return; const it = items[+b.dataset.i]; closeMenus(); it.run && it.run(); });
    place(menuEl, x, y);
    menuEl.querySelector('button')?.focus();
  }
  function emojiPicker(x, y, pick) {
    closeMenus();
    const el = document.createElement('div'); el.className = 'cc-emo';
    el.innerHTML = `<input placeholder="Buscar emoji" aria-label="Buscar emoji"><div class="grid"></div>`;
    const grid = el.querySelector('.grid'), q = el.querySelector('input');
    const draw = () => {
      const t = q.value.trim().toLowerCase();
      grid.innerHTML = (window.CC_EMOJI || []).map(([cat, list]) => {
        const l = t ? list.filter(([, k]) => k.includes(t)) : list;
        return l.length ? (t ? '' : `<h5>${esc(cat)}</h5>`) + l.map(([e, k]) => `<button data-e="${e}" title="${esc(k)}">${e}</button>`).join('') : '';
      }).join('') || '<div class="cc-empty" style="grid-column:1/-1">Nada con ese nombre</div>';
    };
    q.addEventListener('input', draw);
    grid.addEventListener('click', e => { const b = e.target.closest('[data-e]'); if (b) { pick(b.dataset.e); el.remove(); } });
    q.addEventListener('keydown', e => { if (e.key === 'Escape') el.remove(); if (e.key === 'Enter') { const b = grid.querySelector('[data-e]'); if (b) { pick(b.dataset.e); el.remove(); } } });
    draw(); place(el, x, y); q.focus();
  }
  function profileCard(uid, x, y) {
    closeMenus();
    const u = S.users[uid]; if (!u) return;
    const el = document.createElement('div'); el.className = 'cc-card';
    el.innerHTML = `<div class="top"></div>${avatar(uid, 72)}<div class="bd"><h3>${esc(u.name)}</h3><div class="pos">${esc([u.position, u.dept].filter(Boolean).join(' · ') || '@' + u.username)}</div>
      <div class="st"><i class="cc-st ${u.online ? u.status : 'off'}"></i>${esc(statusLabel(u))}</div>
      <dl>${u.email ? `<dt>Correo</dt><dd><a href="mailto:${esc(u.email)}">${esc(u.email)}</a></dd>` : ''}${u.dept ? `<dt>Equipo</dt><dd>${esc(u.dept)}</dd>` : ''}${!u.online && u.last_seen ? `<dt>Visto</dt><dd>${esc(fullWhen(u.last_seen, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }))}</dd>` : ''}${locOf(uid) && TZ ? `<dt>Hora local</dt><dd class="cc-card-tz">${TZ.flag(locOf(uid).cc)} <b data-g7-clock="${esc(locOf(uid).tz)}">${TZ.clock(locOf(uid).tz)}</b> · ${esc(TZ.countryName(locOf(uid).cc))}${uid === S.me ? '' : ` · ${esc(TZ.diffLabel(locOf(uid).tz))}`}</dd>` : ''}</dl>
      <div class="cc-wkbox" data-wk="${uid}" style="margin-bottom:12px"></div>
      <button class="cc-btn pri" style="width:100%" data-dm="${uid}"><i class="fa-regular fa-paper-plane"></i> ${uid === S.me ? 'Abrir mis notas' : 'Enviar mensaje'}</button></div>`;
    el.addEventListener('click', e => { if (e.target.closest('[data-dm]')) { el.remove(); openDM(uid); } });
    place(el, x, y);
    el.querySelectorAll('[data-wk]').forEach(fillWork);
  }
  function statusMenu(x, y) {
    const u = S.users[S.me];
    const presets = ['En reunión', 'Trabajando desde casa', 'De vacaciones', 'Fuera de horario'];
    const items = [{ head: 'Tu estado' }];
    Object.entries(S.statuses).forEach(([k, l]) => items.push({ html: `<i class="cc-st ${k}" style="display:inline-block;width:10px;height:10px;border-radius:50%;position:static"></i>${esc(l)}`, on: u.status === k, run: () => setStatus(k, u.status_text) }));
    items.push('-', { head: 'Mensaje de estado' });
    presets.forEach(p => items.push({ label: p, icon: 'fa-regular fa-comment', on: u.status_text === p, run: () => setStatus(p === 'En reunión' ? 'busy' : p === 'De vacaciones' || p === 'Fuera de horario' ? 'away' : u.status, p) }));
    items.push({ label: 'Escribir otro…', icon: 'fa-solid fa-pen', run: customStatus });
    if (u.status_text) items.push({ label: 'Borrar mensaje de estado', icon: 'fa-solid fa-eraser', run: () => setStatus(u.status, '') });
    menu(items, x, y);
  }
  function customStatus() {
    const u = S.users[S.me];
    dialog(`<div class="cc-dh"><h3>Mensaje de estado</h3></div><div class="cc-db"><input class="cc-in" id="cc-st-t" maxlength="140" value="${esc(u.status_text)}" placeholder="Ej: Volviendo a las 15 h"></div><div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go>Guardar</button></div>`, (ov, close) => {
      const go = () => { setStatus(u.status, $('cc-st-t').value.trim()); close(); };
      ov.querySelector('[data-go]').addEventListener('click', go);
      $('cc-st-t').addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
    });
  }
  async function setStatus(status, text) {
    try { await api('status', { status, text }); Object.assign(S.users[S.me], { status, status_text: text, online: true }); renderMe(); toast('Estado actualizado'); } catch (e) { fail(e); }
  }
  function lightbox(url, name) {
    const lb = document.createElement('div'); lb.className = 'cc-lb';
    lb.innerHTML = `<img src="${esc(url)}" alt="${esc(name)}"><div class="bar"><a href="${esc(url)}${url.includes('?') ? '&' : '?'}dl=1" title="Descargar" aria-label="Descargar"><i class="fa-solid fa-download"></i></a><button aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>`;
    const close = () => { lb.remove(); document.removeEventListener('keydown', k); };
    const k = e => { if (e.key === 'Escape') close(); };
    lb.addEventListener('click', e => { if (!e.target.closest('a')) close(); });
    document.addEventListener('keydown', k);
    document.body.appendChild(lb);
  }
  function setRail() { document.querySelectorAll('.cc-rb[data-view]').forEach(b => b.classList.toggle('on', b.dataset.view === S.view)); }

  async function pref(key) {
    const c = curConv(); if (!c || !S.convs[c.id]) return;
    try { const d = await api('pref', { conv: c.id, [key]: !c[key] }); S.convs[c.id] = d.conv; renderHead(); renderList(); updateBadges(); if (S.aside) renderAside(); toast(key === 'muted' ? (d.conv.muted ? 'Conversación silenciada' : 'Vas a recibir avisos de nuevo') : d.conv.favorite ? 'Agregado a favoritos' : 'Quitado de favoritos'); } catch (e) { fail(e); }
  }
  async function join(id) {
    try { const d = await api('join', { conv: id }); S.convs[d.conv.id] = d.conv; S.browse = null; if (S.view === 'channels') loadBrowse(); openConv(d.conv.id); toast('Te uniste al canal'); } catch (e) { fail(e); }
  }
  async function pickAvatar(remove) {
    const c = curConv(); if (!c) return;
    if (remove) { try { const d = await api('avatar', { conv: c.id, file_id: 0 }); S.convs[c.id] = d.conv; renderAside(); renderHead(); renderList(); } catch (e) { fail(e); } return; }
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/jpeg,image/png,image/webp,image/gif';
    inp.onchange = async () => {
      let f = inp.files[0]; if (!f) return;
      if (window.g7PrepImage) f = await g7PrepImage(f, { max: 600 });
      const a = { name: f.name, size: f.size, type: f.type, prog: 0 }; S.att.push(a);
      const token = hex32();
      try {
        const fd = new FormData(); fd.append('action', 'up_chunk'); fd.append('token', token); fd.append('index', 0); fd.append('chunk', f, 'p');
        const r = await fetch(API, { method: 'POST', body: fd, headers: { 'X-CH': '1' } }); const j = await r.json().catch(() => null);
        if (!j || !j.ok) throw new Error((j && j.error) || 'No se pudo subir la imagen');
        const up = await api('up_done', { token, parts: 1, size: f.size, name: f.name, conv: c.id, avatar: 1 });
        const d = await api('avatar', { conv: c.id, file_id: up.file.id });
        S.convs[c.id] = d.conv; renderAside(); renderHead(); renderList(); toast('Imagen actualizada');
      } catch (e) { fail(e); } finally { S.att = S.att.filter(x => x !== a); }
    };
    inp.click();
  }

  // ══ Avisos: actividad, navegador y sonido ══════════════════════
  function inQuiet() {
    const p = S.prefs; if (!p.quiet_on) return false;
    const now = new Date(), cur = TZ ? TZ.minutesNow() : now.getHours() * 60 + now.getMinutes();   // en la zona de quien mira
    const mm = t => { const [h, m] = String(t || '0:0').split(':').map(Number); return h * 60 + m; };
    const a = mm(p.quiet_from), b = mm(p.quiet_to);
    return a <= b ? cur >= a && cur < b : cur >= a || cur < b;
  }
  let audioCtx = null;
  // kind: 'dm' | 'chat' | 'mention' | 'chatnote' | 'sent' → sonidos de g7-juice.js (MOVIMIENTO.md); sin él, el bip de siempre
  function beep(kind = 'chat') {
    if (!S.prefs.sound || inQuiet()) return;
    if (window.G7Juice && G7Juice.alert) { G7Juice.alert(kind); return; }
    if (kind === 'sent') return;
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      const o = audioCtx.createOscillator(), g = audioCtx.createGain(), t = audioCtx.currentTime;
      o.type = 'sine'; o.frequency.setValueAtTime(880, t); o.frequency.setValueAtTime(1175, t + .09);
      g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(.12, t + .02); g.gain.exponentialRampToValueAtTime(.0001, t + .28);
      o.connect(g).connect(audioCtx.destination); o.start(t); o.stop(t + .3);
    } catch (e) {}
  }
  function popup(title, body, onClick, tag) {
    if (!S.prefs.browser || inQuiet() || !('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const n = new Notification(title, { body: String(body || '').slice(0, 180), tag: tag || undefined, icon: '/toolz/favicon.ico' });
      n.onclick = () => { window.focus(); n.close(); onClick && onClick(); };
    } catch (e) {}
  }
  const watching = cid => S.open === cid && !document.hidden && document.hasFocus();
  /** Mensaje nuevo de otra persona: ¿corresponde avisar? (las menciones y anuncios llegan por la actividad) */
  function maybeNotify(m) {
    if (m.user_id === S.me || m.kind === 'system' || m.parent_id || m.mentions_me || m.kind === 'announce' || m.deleted || m.edited) return;
    if (S.notified.has(m.id) || Date.now() - D(m.created_at) > 120000) return;
    S.notified.add(m.id);
    const c = S.convs[m.conv_id]; if (!c || c.muted || c.notify !== 'all' || watching(c.id)) return;
    const want = c.kind === 'dm' ? S.prefs.dm : c.kind === 'group' ? S.prefs.groups : S.prefs.channels;
    if (!want) return;
    beep(c.kind === 'dm' ? 'dm' : 'chat');
    const where = c.kind === 'dm' ? uname(m.user_id) : (c.kind === 'channel' ? '#' : '') + c.name;
    popup(where, (c.kind === 'dm' ? '' : uname(m.user_id).split(' ')[0] + ': ') + (m.body || (m.poll ? '📊 ' + m.poll.question : '📎 Archivo')), () => openConv(c.id), 'c' + c.id);
  }
  const NOTIF = {
    mention: ['fa-at', 'te mencionó'], reply: ['fa-comments', 'respondió en un hilo que sigues'], reaction: ['fa-face-smile', 'reaccionó a tu mensaje'],
    invite: ['fa-user-plus', 'te sumó a'], pin: ['fa-thumbtack', 'fijó tu mensaje'], announce: ['fa-bullhorn', 'publicó un anuncio'],
    reminder: ['fa-bell', 'Recordatorio'], sched_fail: ['fa-triangle-exclamation', 'No se pudo mandar un mensaje programado'],
  };
  function notifTitle(n) {
    const c = S.convs[n.conv_id];
    const where = c ? ' en ' + (c.kind === 'channel' ? '#' + c.name : c.kind === 'group' ? c.name : 'un mensaje directo') : '';
    if (n.kind === 'reminder' || n.kind === 'sched_fail') return NOTIF[n.kind][1];
    if (n.kind === 'invite') return `${uname(n.actor_id)} te sumó a ${c ? (c.kind === 'channel' ? '#' : '') + c.name : 'una conversación'}`;
    return `${uname(n.actor_id)} ${(NOTIF[n.kind] || ['', 'hizo algo'])[1]}${n.kind === 'invite' ? '' : where}`;
  }
  function onNotifs(items, unread) {
    S.notifUnread = unread ?? S.notifUnread;
    if (S.notifs) items.forEach(n => { if (!S.notifs.some(x => x.id === n.id)) S.notifs.unshift(n); });
    items.forEach(n => {
      if (n.read || S.notified.has('n' + n.id)) return;
      S.notified.add('n' + n.id);
      const c = n.conv_id && S.convs[n.conv_id];
      if (c && c.notify === 'none') return;
      if (n.message_id && watching(n.conv_id) && n.kind !== 'reply') return;
      beep(n.kind === 'mention' ? 'mention' : n.kind === 'reminder' ? 'remind' : 'chatnote');
      popup(notifTitle(n), n.kind === 'reaction' ? n.extra : n.extra, () => openNotif(n), 'n' + n.id);
    });
    updateBadges();
    if (S.view === 'activity') renderList();
  }
  async function loadNotifs() { try { const d = await api('notifs'); S.notifs = d.items; S.notifUnread = d.unread; S.notifMore = d.more; } catch (e) { S.notifs = []; fail(e); } updateBadges(); if (S.view === 'activity') renderList(); }
  async function openNotif(n) {
    if (!n.read) { n.read = true; S.notifUnread = Math.max(0, S.notifUnread - 1); updateBadges(); api('notif_read', { id: n.id }).catch(() => {}); }
    if (n.kind === 'reply' && n.message_id) { try { const d = await api('locate', { id: n.message_id }); await openConv(d.conv, { around: d.id }); openThread(d.id); } catch (e) { fail(e); } return; }
    if (n.message_id) return gotoMessage(n.message_id);
    if (n.kind === 'sched_fail') { S.view = 'apps'; setRail(); loadApps(); renderList(); return; }
    if (n.conv_id && S.convs[n.conv_id]) openConv(n.conv_id);
    else if (S.view === 'activity') renderList();
  }
  function activityHtml() {
    if (!S.notifs) return '<div class="cc-sk"></div>'.repeat(6);
    const list = S.notifFilter === 'unread' ? S.notifs.filter(n => !n.read) : S.notifFilter === 'mention' ? S.notifs.filter(n => n.kind === 'mention' || n.kind === 'reply') : S.notifs;
    if (!list.length) return `<div class="cc-empty"><i class="fa-regular fa-bell big"></i><b>${S.notifFilter === 'all' ? 'Sin actividad todavía' : 'Nada por acá'}</b>Acá aparecen menciones, respuestas, reacciones, invitaciones, anuncios y recordatorios.</div>`;
    return list.map(n => `<button class="cc-notif${n.read ? '' : ' unread'}" data-notif="${n.id}">
      <span class="ni">${n.actor_id ? avatar(n.actor_id, 34, false) : `<span class="cc-hash" style="--s:34px"><i class="fa-solid ${(NOTIF[n.kind] || ['fa-bell'])[0]}" style="font-size:.8em"></i></span>`}<i class="fa-solid ${(NOTIF[n.kind] || ['fa-bell'])[0]} nk"></i></span>
      <span class="cc-rt"><b>${esc(notifTitle(n))}</b>${n.extra ? `<span class="cc-rp">${esc(n.extra)}</span>` : ''}<time>${esc(shortWhen(n.created_at))}</time></span></button>`).join('')
      + (S.notifMore ? `<div class="cc-more"><button class="cc-btn sm" data-act="notif-more">Ver más</button></div>` : '');
  }

  // ══ Hilos ══════════════════════════════════════════════════════
  async function openThread(rootId) {
    const root = msgById(rootId);
    S.thread = { root: root || { id: rootId, conv_id: S.open, user_id: 0, body: '', created_at: new Date(), reactions: [], files: [] }, replies: [], loading: true, following: false };
    S.aside = 'thread'; cc.classList.add('aside-on'); renderThread(); renderHead();
    try {
      const d = await api('thread', { id: rootId });
      if (!S.thread || S.thread.root.id !== rootId) return;
      S.thread.root = d.root; S.thread.replies = d.replies; S.thread.loading = false;
      const last = d.replies.length ? d.replies[d.replies.length - 1].id : 0;
      const st = await api('thread_state', { id: rootId, read: last });
      S.thread.following = st.following;
      renderThread();
      const b = $('cc-tmsgs'); if (b) b.scrollTop = b.scrollHeight;
      if (matchMedia('(pointer:fine)').matches) $('cc-tta')?.focus();
    } catch (e) { fail(e); }
  }
  function threadUpsert(m) {
    const T = S.thread; if (!T || m.parent_id !== T.root.id) return;
    const i = T.replies.findIndex(x => x.id === m.id || (m.client_id && x.client_id === m.client_id && x.pending !== undefined));
    if (i >= 0) T.replies[i] = m; else T.replies.push(m);
    T.replies.sort((a, b) => (a.pending ? 1e15 : a.id) - (b.pending ? 1e15 : b.id));
    renderThread();
    if (S.aside === 'thread' && !document.hidden) api('thread_state', { id: T.root.id, read: m.id }).catch(() => {});
  }
  function renderThread() {
    if (S.aside !== 'thread' || !S.thread) return;
    const T = S.thread, c = S.convs[T.root.conv_id] || curConv();
    const box = $('cc-tmsgs'), wasBottom = !box || box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    const hadFocus = document.activeElement && document.activeElement.id === 'cc-tta', txt = $('cc-tta') ? $('cc-tta').value : (T.draft || '');
    const C = S.conv[T.root.conv_id];
    let html = msgHtml(T.root, null, C, true);
    html += `<div class="cc-day">${T.loading ? 'Cargando…' : T.replies.length ? `${T.replies.length} ${T.replies.length === 1 ? 'respuesta' : 'respuestas'}` : 'Sin respuestas todavía'}</div>`;
    let prev = null; for (const m of T.replies) { html += msgHtml(m, prev, C, true); prev = m; }
    const canPost = C ? C.canPost : !!S.convs[T.root.conv_id];
    keepMedia(box, () => { aside.innerHTML = `<div class="cc-ah"><h3>Hilo <small style="font-weight:500;color:var(--g7-text-4);font-size:12px">${c ? (c.kind === 'channel' ? '#' + esc(c.name) : esc(convName(c))) : ''}</small></h3>
      <button class="cc-btn sm" data-act="follow">${T.following ? '<i class="fa-solid fa-bell-slash"></i> Dejar de seguir' : '<i class="fa-regular fa-bell"></i> Seguir'}</button>
      <button class="cc-ib" data-act="aside-close" aria-label="Cerrar hilo"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-msgs cc-tmsgs" id="cc-tmsgs">${html}</div>
      ${canPost ? `<div class="cc-comp"><div class="cc-box"><div class="cc-input"><textarea id="cc-tta" rows="1" placeholder="Responder en el hilo…" aria-label="Responder en el hilo"></textarea><button class="cc-send" data-act="thread-send" aria-label="Enviar respuesta"><i class="fa-solid fa-paper-plane"></i></button></div></div></div>` : ''}`; }, aside);
    const ta = $('cc-tta'); if (ta) { ta.value = txt; autosize(ta); if (hadFocus) { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); } }
    const nb = $('cc-tmsgs'); if (nb && wasBottom) nb.scrollTop = nb.scrollHeight;
  }
  async function sendThread() {
    const T = S.thread, ta = $('cc-tta'); if (!T || !ta) return;
    const body = ta.value.trim(); if (!body) return;
    const m = { id: 'p' + newId(), client_id: newId(), conv_id: T.root.conv_id, parent_id: T.root.id, user_id: S.me, kind: 'text', body, created_at: new Date(), pending: true, reactions: [], files: [] };
    T.replies.push(m); ta.value = ''; T.draft = ''; renderThread();
    try {
      const d = await api('send', { conv: T.root.conv_id, body, client_id: m.client_id, parent_id: T.root.id });
      const i = T.replies.indexOf(m); if (i >= 0) T.replies[i] = d.message;
      T.following = true;
      if (/https?:\/\//.test(body)) api('unfurl', { id: d.message.id }).then(x => x.message && threadUpsert(x.message)).catch(() => {});
    } catch (e) { m.pending = false; m.failed = true; fail(e); }
    renderThread();
  }

  // ══ Menú «Más» de un mensaje ═══════════════════════════════════
  function moreMenu(m, x, y) {
    const mine = m.user_id === S.me, C = S.conv[m.conv_id], c = S.convs[m.conv_id];
    const items = [];
    if (m.body) items.push({ label: 'Copiar texto', icon: 'fa-regular fa-copy', run: async () => { try { await navigator.clipboard.writeText(m.body); toast('Texto copiado'); } catch (e) { toast('No se pudo copiar', true); } } });
    items.push({ label: 'Copiar enlace al mensaje', icon: 'fa-solid fa-link', run: async () => { try { await navigator.clipboard.writeText(`${location.origin}/toolz/chat/?m=${m.id}`); toast('Enlace copiado'); } catch (e) { toast('No se pudo copiar', true); } } });
    items.push({ label: 'Reenviar', icon: 'fa-solid fa-share', run: () => forwardDlg(m) });
    items.push(m.saved ? { label: 'Quitar de guardados', icon: 'fa-solid fa-bookmark', run: () => saveMsg(m, false) } : { label: 'Guardar…', icon: 'fa-regular fa-bookmark', run: () => saveDlg(m) });
    if (c && !m.parent_id && !(c.announce && !(C && C.canManage))) items.push({ label: m.pinned ? 'Desfijar' : 'Fijar en la conversación', icon: 'fa-solid fa-thumbtack', run: () => pinMsg(m, !m.pinned) });
    items.push({ label: 'Recordármelo…', icon: 'fa-regular fa-bell', run: () => remindDlg({ message: m }) });
    if (S.canTasks) items.push({ label: 'Convertir en tarea…', icon: 'fa-solid fa-list-check', run: () => taskDlg(m) });
    if (S.canTasks) items.push({ label: mine ? 'Abrir un ticket…' : 'Abrir ticket (lo atiendo yo)…', icon: 'fa-solid fa-ticket', run: () => ticketDlg(m) });
    if (S.canTasks) items.push({ label: 'Añadir nota a ticket…', icon: 'fa-solid fa-note-sticky', run: () => noteDlg('', m) });
    if (mine || (C && C.canManage)) items.push('-', { label: 'Eliminar', icon: 'fa-regular fa-trash-can', red: true, run: () => askDelete(m) });
    menu(items, x, y);
  }
  async function pinMsg(m, on) { try { const d = await api('pin', { id: m.id, pinned: on }); upsert(d.message); renderMsgs(); toast(on ? 'Mensaje fijado' : 'Mensaje desfijado'); if (S.info) loadInfo(); } catch (e) { fail(e); } }
  async function saveMsg(m, on, note = '', category = '') {
    try { const d = await api('save', on ? { id: m.id, note, category } : { id: m.id, saved: false }); upsert(d.message); renderMsgs(); toast(on ? 'Guardado' : 'Quitado de guardados'); if (S.view === 'saved') loadSaved(); } catch (e) { fail(e); }
  }
  function saveDlg(m) {
    const cats = [...new Set((S.saved || []).map(x => x.saved && x.saved.category).filter(Boolean))];
    dialog(`<div class="cc-dh"><h3>${m.saved ? 'Editar guardado' : 'Guardar mensaje'}</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><div class="cc-fld"><label for="cc-sv-n">Nota personal <span style="text-transform:none;font-weight:400">(opcional, solo tú la ves)</span></label><textarea class="cc-in" id="cc-sv-n" maxlength="500">${esc(m.saved ? m.saved.note : '')}</textarea></div>
      <div class="cc-fld"><label for="cc-sv-c">Categoría <span style="text-transform:none;font-weight:400">(opcional)</span></label><input class="cc-in" id="cc-sv-c" list="cc-sv-l" maxlength="60" value="${esc(m.saved ? m.saved.category : '')}" placeholder="Ej: Para revisar, Ideas"><datalist id="cc-sv-l">${cats.map(k => `<option value="${esc(k)}">`).join('')}</datalist></div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go>Guardar</button></div>`, (ov, close) => {
      ov.querySelector('[data-go]').addEventListener('click', () => { const note = $('cc-sv-n').value, cat = $('cc-sv-c').value; close(); saveMsg(m, true, note, cat); });
    });
  }
  function convPicker(ov, onPick) {
    const box = ov.querySelector('.cc-pick'), q = ov.querySelector('[data-pq]');
    const draw = () => {
      const t = (q.value || '').toLowerCase();
      const list = Object.values(S.convs).filter(c => !c.archived && convName(c).toLowerCase().includes(t)).sort(byRecent).slice(0, 60);
      box.innerHTML = list.map(c => `<label><input type="radio" name="cp" value="${c.id}">${convIcon(c, 28)}<span class="cc-rt"><b>${c.kind === 'channel' ? '#' : ''}${esc(convName(c))}</b><small>${c.kind === 'dm' ? 'Mensaje directo' : c.kind === 'group' ? 'Grupo' : 'Canal'}</small></span></label>`).join('') || '<div class="cc-empty">Nada coincide</div>';
    };
    box.addEventListener('change', e => onPick(+e.target.value));
    q.addEventListener('input', draw); draw();
  }
  function forwardDlg(m) {
    let dest = 0;
    dialog(`<div class="cc-dh"><h3>Reenviar mensaje</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><div class="cc-fwd" style="margin:0 0 12px"><small><b>${esc(uname(m.user_id))}</b></small><div class="cc-body">${fmt((m.body || '📎 Archivo').slice(0, 300))}</div></div>
      <input class="cc-in" data-pq placeholder="¿A qué conversación?" aria-label="Buscar conversación"><div class="cc-pick"></div>
      <div class="cc-fld" style="margin-top:10px"><label for="cc-fw-c">Comentario <span style="text-transform:none;font-weight:400">(opcional)</span></label><input class="cc-in" id="cc-fw-c" maxlength="2000"></div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go disabled>Reenviar</button></div>`, (ov, close) => {
      const go = ov.querySelector('[data-go]');
      convPicker(ov, id => { dest = id; go.disabled = false; });
      go.addEventListener('click', async () => {
        go.disabled = true;
        try { await api('forward', { id: m.id, conv: dest, comment: $('cc-fw-c').value }); close(); toast('Mensaje reenviado'); if (dest !== S.open) openConv(dest); } catch (e) { fail(e); go.disabled = false; }
      });
    });
  }
  const pad2 = n => String(n).padStart(2, '0');
  // Los campos de fecha y hora muestran y leen la hora de pared de quien mira (su zona); al servidor va en la de almacenamiento
  const localDT = d => TZ ? TZ.inputValue(d) : `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
  const atToServer = at => TZ ? TZ.toStore(TZ.fromInput(at)) : at.replace('T', ' ') + ':00';
  const atToDate = at => (TZ && TZ.fromInput(at)) || at;
  function presets() {
    const n = new Date();
    let t, mon;
    if (TZ) { t = TZ.wallFromToday(1, 9); mon = TZ.wallFromToday((8 - TZ.weekday()) % 7 || 7, 9); }
    else { t = new Date(n); t.setDate(t.getDate() + 1); t.setHours(9, 0, 0, 0); mon = new Date(n); mon.setDate(n.getDate() + ((8 - n.getDay()) % 7 || 7)); mon.setHours(9, 0, 0, 0); }
    return [['En 20 minutos', new Date(+n + 20 * 60000)], ['En 1 hora', new Date(+n + 3600000)], ['En 3 horas', new Date(+n + 3 * 3600000)], ['Mañana a las 9', t], ['El lunes a las 9', mon]];
  }
  function whenField(id, def) {
    return `<div class="cc-presets">${presets().map(([l, d]) => `<button type="button" class="cc-btn sm" data-preset="${localDT(d)}" data-for="${id}">${l}</button>`).join('')}</div>
      <input type="datetime-local" class="cc-in" id="${id}" value="${localDT(def)}" min="${localDT(new Date())}" style="margin-top:8px">`;
  }
  const fmtWhen = s => fullWhen(s, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  function remindDlg({ message, shared } = {}) {
    const c = curConv();
    dialog(`<div class="cc-dh"><h3>${shared ? 'Recordatorio para la conversación' : 'Recordatorio'}</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db">${message ? `<p class="sub">Te aviso sobre el mensaje de <b>${esc(uname(message.user_id))}</b>.</p>` : ''}
      <div class="cc-fld"><label for="cc-rm-t">${message ? 'Nota (opcional)' : 'Qué hay que recordar'}</label><input class="cc-in" id="cc-rm-t" maxlength="500" placeholder="${message ? 'Ej: responderle a la tarde' : 'Ej: mandar el informe semanal'}"></div>
      <div class="cc-fld"><label>Cuándo</label>${whenField('cc-rm-at', new Date(Date.now() + 3600000))}</div>
      ${!message && c && S.convs[c.id] ? `<label class="cc-check"><input type="checkbox" id="cc-rm-sh" ${shared ? 'checked' : ''}> Avisar a todos en ${c.kind === 'channel' ? '#' + esc(c.name) : esc(convName(c))} (se publica como mensaje)</label>` : ''}</div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go>Crear recordatorio</button></div>`, (ov, close) => {
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        const at = $('cc-rm-at').value; if (!at) return toast('Elige cuándo', true);
        const sh = $('cc-rm-sh') && $('cc-rm-sh').checked;
        e.target.disabled = true;
        try {
          await api('remind_create', { text: $('cc-rm-t').value, at: atToServer(at), message_id: message ? message.id : 0, conv: message ? 0 : (sh || c ? c.id : 0), shared: sh });
          close(); toast('Te aviso el ' + fmtWhen(atToDate(at))); if (S.view === 'apps') loadApps();
        } catch (err) { fail(err); e.target.disabled = false; }
      });
    });
  }
  async function taskDlg(m) {
    let cfg; try { cfg = await api('task_cfg'); } catch (e) { return fail(e); }
    const c = S.convs[m.conv_id];
    const first = (m.body || '').split('\n')[0].replace(/[*_~`>]/g, '').trim().slice(0, 120);
    dialog(`<div class="cc-dh"><h3>Convertir en tarea</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><div class="cc-fld"><label for="cc-tk-t">Título</label><input class="cc-in" id="cc-tk-t" maxlength="250" value="${esc(first || 'Tarea desde el chat')}"></div>
      <div class="cc-fld"><label for="cc-tk-d">Descripción</label><textarea class="cc-in" id="cc-tk-d" maxlength="5000">${esc(m.body || '')}</textarea></div>
      ${cfg.boards.length ? '' : '<p class="cc-appe">No tienes tableros donde crear tareas.</p>'}
      <div class="cc-fld"><label for="cc-tk-b">Tablero</label><select class="cc-in" id="cc-tk-b">${cfg.boards.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="cc-fld"><label for="cc-tk-s">Estado</label><select class="cc-in" id="cc-tk-s">${cfg.statuses.map(s => `<option value="${esc(s.id)}">${esc(s.name)}</option>`).join('')}</select></div>
        <div class="cc-fld"><label for="cc-tk-p">Prioridad</label><select class="cc-in" id="cc-tk-p">${cfg.priorities.map(p => `<option value="${esc(p.id)}" ${p.id === cfg.default_priority ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></div>
      </div>
      <div class="cc-fld"><label for="cc-tk-due">Fecha límite <span style="text-transform:none;font-weight:400">(opcional)</span></label><input type="date" class="cc-in" id="cc-tk-due"></div>
      <div class="cc-fld"><label>Responsables</label><input class="cc-in" data-pq placeholder="Buscar persona" aria-label="Buscar persona"><div class="cc-chips"></div><div class="cc-pick" style="max-height:170px"></div></div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go>Crear tarea</button></div>`, (ov, close) => {
      const chosen = peoplePicker(ov, []);
      // Cada tablero tiene sus estados
      $('cc-tk-b')?.addEventListener('change', e => { const b = cfg.boards.find(x => x.id === +e.target.value); $('cc-tk-s').innerHTML = (b?.statuses || []).map(s => `<option value="${esc(s.id)}">${esc(s.name)}</option>`).join(''); });
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        e.target.disabled = true;
        try {
          const d = await api('task_from_msg', { id: m.id, board_id: +($('cc-tk-b')?.value || 0), title: $('cc-tk-t').value, description: $('cc-tk-d').value, status: $('cc-tk-s').value, priority: $('cc-tk-p').value, due_date: $('cc-tk-due').value, assignees: [...chosen] });
          close(); upsert(d.message); renderMsgs(); toast('Tarea creada');
        } catch (err) { fail(err); e.target.disabled = false; }
      });
    });
    void c;
  }
  async function ticketDlg(m, assignee = 0) {
    // Quién pide y quién atiende (chat/lib2.php ch_ticket_roles): mensaje de otra persona → lo pidió ella y lo atiendo yo;
    // mensaje mío → lo atiende la otra persona (en un grupo, la que se elija)
    let cfg; try { cfg = await api('task_cfg', { message: m.id, assignee }); } catch (e) { return fail(e); }
    if (!cfg.cats.length) { toast('No hay categorías de tickets activas'); return; }
    const R = cfg.roles || {}, c = S.convs[m.conv_id];
    if (R.mine && !R.assignee && c && c.kind !== 'dm') return pickMember(c, '¿Quién atiende el ticket?', uid => ticketDlg(m, uid));
    const first = (m.body || '').split('\n')[0].replace(/[*_~`>]/g, '').trim().slice(0, 120);
    const roleLine = `<div class="cc-tk-roles"><span><small>Lo pide</small>${avatar(R.requester || S.me, 22, false)}<b>${esc(R.requester === S.me ? 'Tú' : uname(R.requester))}</b></span>
      <i class="fa-solid fa-arrow-right"></i><span><small>Lo atiende</small>${R.assignee ? avatar(R.assignee, 22, false) + `<b>${esc(R.assignee === S.me ? 'Tú' : uname(R.assignee))}</b>` : '<b>Su departamento</b>'}</span>
      ${R.team ? `<span><small>Departamento</small><b>${esc(R.team)}</b></span>` : ''}</div>`;
    dialog(`<div class="cc-dh"><h3>Abrir un ticket</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db">${roleLine}<div class="cc-fld"><label for="cc-tt-s">Asunto</label><input class="cc-in" id="cc-tt-s" maxlength="200" value="${esc(first || 'Ticket desde el chat')}"></div>
      <div class="cc-fld"><label for="cc-tt-d">Qué pasa</label><textarea class="cc-in" id="cc-tt-d" maxlength="20000">${esc(m.body || '')}</textarea></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="cc-fld"><label for="cc-tt-c">Categoría</label><select class="cc-in" id="cc-tt-c">${cfg.cats.map(x => `<option value="${x.id}"${x.id === R.category_id ? ' selected' : ''}>${esc(x.name)}</option>`).join('')}</select></div>
        <div class="cc-fld"><label for="cc-tt-p">Prioridad</label><select class="cc-in" id="cc-tt-p">${cfg.priorities.map(p => `<option value="${esc(p.id)}" ${p.id === cfg.default_priority ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></div></div>
      <p class="cc-appe" style="margin:0">Es una tarea rápida: tiene el plazo de los tickets del chat (Trabajo › Tickets › Configuración). El mensaje queda enlazado.</p></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go>Abrir ticket</button></div>`, (ov, close) => {
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        e.target.disabled = true;
        try {
          const d = await api('ticket_from_msg', { id: m.id, subject: $('cc-tt-s').value, description: $('cc-tt-d').value, category_id: +$('cc-tt-c').value, priority: $('cc-tt-p').value, assignee: R.assignee || 0 });
          close(); upsert(d.message); renderMsgs(); toast('Ticket abierto');
        } catch (err) { fail(err); e.target.disabled = false; }
      });
    });
  }
  function pollDlg() {
    const c = curConv(); if (!c) return;
    dialog(`<div class="cc-dh"><h3>Nueva encuesta</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><div class="cc-fld"><label for="cc-po-q">Pregunta</label><input class="cc-in" id="cc-po-q" maxlength="300" placeholder="Ej: ¿Qué día hacemos la reunión?"></div>
      <div class="cc-fld"><label>Opciones</label><div id="cc-po-o"></div><button type="button" class="cc-btn sm" data-act="poll-addopt" style="margin-top:6px"><i class="fa-solid fa-plus"></i> Agregar opción</button></div>
      <label class="cc-check"><input type="checkbox" id="cc-po-m"> Permitir elegir varias opciones</label>
      <label class="cc-check"><input type="checkbox" id="cc-po-a"> Votación anónima (no se ve quién votó qué)</label></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go>Publicar encuesta</button></div>`, (ov, close) => {
      const box = $('cc-po-o');
      const add = (v = '') => { if (box.children.length >= 12) return; const d = document.createElement('div'); d.className = 'cc-optrow'; d.innerHTML = `<input class="cc-in" maxlength="120" placeholder="Opción ${box.children.length + 1}" value="${esc(v)}"><button type="button" class="cc-ib" aria-label="Quitar opción"><i class="fa-solid fa-xmark"></i></button>`; d.querySelector('button').onclick = () => { if (box.children.length > 2) d.remove(); }; box.appendChild(d); };
      add(); add();
      ov.querySelector('[data-act="poll-addopt"]').addEventListener('click', e => { e.stopPropagation(); add(); box.lastChild.querySelector('input').focus(); });
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        const options = [...box.querySelectorAll('input')].map(i => i.value.trim()).filter(Boolean);
        e.target.disabled = true;
        try { const d = await api('poll_create', { conv: c.id, question: $('cc-po-q').value, options, multi: $('cc-po-m').checked, anonymous: $('cc-po-a').checked }); close(); upsert(d.message); renderMsgs(); $('cc-msgs').scrollTop = 1e9; }
        catch (err) { fail(err); e.target.disabled = false; }
      });
    });
  }
  async function votePoll(m, i) {
    const p = m.poll; let opts;
    if (p.multi) opts = p.mine.includes(i) ? p.mine.filter(x => x !== i) : [...p.mine, i];
    else opts = p.mine.includes(i) ? [] : [i];
    try { const d = await api('poll_vote', { id: m.id, opts }); upsert(d.message); renderMsgs(); } catch (e) { fail(e); }
  }
  function schedDlg(item) {
    const c = item ? S.convs[item.conv_id] : curConv(); if (!c) return;
    const ta = $('cc-ta'); const text = item ? item.body : (ta ? ta.value : '');
    const files = item ? [] : S.att.filter(a => a.file);
    dialog(`<div class="cc-dh"><h3>${item ? 'Editar mensaje programado' : 'Programar mensaje'}</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><p class="sub">Se publica en ${c.kind === 'channel' ? '#' + esc(c.name) : esc(convName(c))} a tu nombre.</p>
      <div class="cc-fld"><label for="cc-sc-b">Mensaje</label><textarea class="cc-in" id="cc-sc-b" maxlength="8000">${esc(text)}</textarea>${files.length ? `<small style="color:var(--g7-text-3)"><i class="fa-solid fa-paperclip"></i> ${files.length} ${files.length === 1 ? 'archivo adjunto' : 'archivos adjuntos'}</small>` : ''}</div>
      <div class="cc-fld"><label>Cuándo</label>${whenField('cc-sc-at', item ? D(item.at) : new Date(Date.now() + 3600000))}</div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go><i class="fa-regular fa-clock"></i> ${item ? 'Guardar' : 'Programar'}</button></div>`, (ov, close) => {
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        const at = $('cc-sc-at').value; if (!at) return toast('Elige cuándo', true);
        e.target.disabled = true;
        try {
          await api(item ? 'sched_update' : 'sched_create', { id: item ? item.id : 0, conv: c.id, body: $('cc-sc-b').value, at: atToServer(at), files: files.map(a => a.file.id) });
          close(); toast('Programado para el ' + fmtWhen(atToDate(at)));
          if (!item && ta) { ta.value = ''; S.draft = ''; LS.set('draft.' + c.id, ''); autosize(ta); S.att = []; renderAtt(); }
          if (S.view === 'apps') loadApps();
        } catch (err) { fail(err); e.target.disabled = false; }
      });
    });
  }
  function announceDlg() {
    const c = curConv(); if (!c) return;
    let img = null;
    dialog(`<div class="cc-dh"><h3>Nuevo anuncio en #${esc(c.name)}</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><p class="sub">Se destaca en el canal y les llega un aviso a todos sus miembros.</p>
      <div class="cc-fld"><label for="cc-an-t">Título</label><input class="cc-in" id="cc-an-t" maxlength="160"></div>
      <div class="cc-fld"><label for="cc-an-b">Texto</label><textarea class="cc-in" id="cc-an-b" maxlength="8000" style="min-height:120px"></textarea></div>
      <div class="cc-fld"><label>Imagen <span style="text-transform:none;font-weight:400">(opcional)</span></label><div style="display:flex;align-items:center;gap:10px"><button type="button" class="cc-btn sm" data-act="an-img"><i class="fa-regular fa-image"></i> Elegir imagen</button><span id="cc-an-in" style="font-size:12px;color:var(--g7-text-3)"></span></div></div>
      <label class="cc-check"><input type="checkbox" id="cc-an-sch"> Publicar más tarde</label><div id="cc-an-w" hidden>${whenField('cc-an-at', new Date(Date.now() + 3600000))}</div></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go><i class="fa-solid fa-bullhorn"></i> Publicar</button></div>`, (ov, close) => {
      $('cc-an-sch').addEventListener('change', e => { $('cc-an-w').hidden = !e.target.checked; });
      ov.querySelector('[data-act="an-img"]').addEventListener('click', e => {
        e.stopPropagation();
        const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/jpeg,image/png,image/webp,image/gif';
        inp.onchange = async () => {
          let f = inp.files[0]; if (!f) return;
          if (window.g7PrepImage) f = await g7PrepImage(f, { max: 1600 });
          $('cc-an-in').textContent = 'Subiendo…';
          const a = { name: f.name, size: f.size, type: f.type, prog: 0 };
          await upload(f, a, c.id, true);
          if (a.file) { img = a.file; $('cc-an-in').textContent = '✓ ' + f.name; } else $('cc-an-in').textContent = a.err || 'No se pudo subir';
        };
        inp.click();
      });
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        const later = $('cc-an-sch').checked, at = later ? $('cc-an-at').value : '';
        e.target.disabled = true;
        try {
          const d = await api('announce', { conv: c.id, title: $('cc-an-t').value, body: $('cc-an-b').value, image_file: img ? img.id : 0, at: at ? atToServer(at) : '' });
          close();
          if (d.scheduled) toast('Anuncio programado para el ' + fmtWhen(atToDate(at))); else { upsert(d.message); renderMsgs(); $('cc-msgs').scrollTop = 1e9; toast('Anuncio publicado'); }
        } catch (err) { fail(err); e.target.disabled = false; }
      });
    });
  }
  function plusMenu(x, y) {
    const c = curConv(), C = c && S.conv[c.id];
    const items = [
      { label: 'Encuesta', icon: 'fa-solid fa-square-poll-vertical', run: pollDlg },
      { label: 'Programar este mensaje', icon: 'fa-regular fa-clock', run: () => schedDlg() },
      { label: 'Recordatorio', icon: 'fa-regular fa-bell', run: () => remindDlg({}) },
    ];
    if (c && c.kind === 'channel' && C && C.canManage) items.push({ label: 'Anuncio destacado', icon: 'fa-solid fa-bullhorn', run: announceDlg });
    menu(items, x, y);
  }

  // ══ Acciones rápidas: botón ⚡ y comandos con / (CHAT_ACCIONES.md) ══════
  const QA = [
    { cmd: 'ticket', icon: 'fa-ticket', label: 'Crear ticket', ex: '/ticket arreglar el banner de la home', sub: 'Lo atiende la otra persona, en su departamento', tasks: true },
    { cmd: 'tarea', icon: 'fa-list-check', label: 'Crear tarea', ex: '/tarea preparar la presentación', sub: 'Asignada a la otra persona', tasks: true },
    { cmd: 'nota', icon: 'fa-note-sticky', label: 'Añadir nota a ticket', ex: '/nota T-0012 ya hablé con el cliente', sub: 'Nota interna en un ticket que atiendes', tasks: true },
    { cmd: 'recordar', icon: 'fa-bell', label: 'Recordatorio', ex: '/recordar llamar al cliente', sub: 'Te aviso cuando elijas' },
    { cmd: 'programar', icon: 'fa-clock', label: 'Programar mensaje', ex: '/programar el informe está listo', sub: 'Se envía más tarde' },
    { cmd: 'encuesta', icon: 'fa-square-poll-vertical', label: 'Encuesta', ex: '/encuesta ¿Qué día nos juntamos?', sub: 'Pregunta con opciones' },
    { cmd: 'ayuda', icon: 'fa-circle-question', label: 'Ver comandos', ex: '/ayuda', sub: 'Todas las acciones rápidas' },
  ];
  const qaList = () => QA.filter(a => !a.tasks || S.canTasks);
  /** Interpreta "/comando texto". Devuelve {a, text} si es una acción conocida (y permitida), o null. */
  function qaParse(body) {
    const mm = /^\/([a-zñ]+)(?:\s+([\s\S]*))?$/i.exec(body || ''); if (!mm) return null;
    const a = qaList().find(x => x.cmd === mm[1].toLowerCase());
    return a ? { a, text: (mm[2] || '').trim() } : null;
  }
  function qaRun(cmd, text) {
    const ta = $('cc-ta');
    if (cmd === 'ticket' || cmd === 'tarea') return quickCreate(cmd, text);
    if (cmd === 'nota') return noteDlg(text);
    if (cmd === 'recordar') { remindDlg({}); const f = $('cc-rm-t'); if (f) f.value = text; return; }
    if (cmd === 'programar') { if (ta) { ta.value = text; S.draft = text; autosize(ta); updateSend(); } return schedDlg(); }
    if (cmd === 'encuesta') { pollDlg(); const f = $('cc-po-q'); if (f) f.value = text; return; }
    if (cmd === 'ayuda') return qaHelp();
  }
  function qaMenu(x, y) {
    const ta = $('cc-ta');
    menu([{ head: 'Acciones rápidas' }, ...qaList().map(a => ({ label: a.label, icon: 'fa-solid ' + a.icon, run: () => {
      // Con texto escrito, la acción lo usa; sin texto, deja el comando listo para escribir
      const text = ta ? ta.value.trim() : '';
      if (text && !text.startsWith('/') && a.cmd !== 'ayuda') { ta.value = ''; S.draft = ''; LS.set('draft.' + S.open, ''); autosize(ta); updateSend(); qaRun(a.cmd, text); }
      else if (a.cmd === 'ayuda' || a.cmd === 'encuesta' || a.cmd === 'recordar') qaRun(a.cmd, '');
      else if (ta) { ta.value = '/' + a.cmd + ' '; ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); autosize(ta); updateSend(); }
    } }))], x, y);
  }
  function qaHelp() {
    dialog(`<div class="cc-dh"><h3><i class="fa-solid fa-bolt" style="color:var(--g7-accent)"></i> Acciones rápidas</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><p class="sub">Escribe <b>/</b> al principio del mensaje o usa el botón <i class="fa-solid fa-bolt"></i>. Con texto ya escrito, el botón lo usa directamente.</p>
      <div class="cc-qa-help">${qaList().map(a => `<div><i class="fa-solid ${a.icon}"></i><span><b>${esc(a.label)}</b><code>${esc(a.ex)}</code><small>${esc(a.sub)}</small></span></div>`).join('')}</div>
      <p class="cc-appe" style="margin:12px 0 0">En un mensaje de otra persona, el menú <b>⋯ › Abrir un ticket</b> lo deja a su nombre y te lo asigna a ti, en tu departamento.</p></div>
      <div class="cc-df"><button class="cc-btn pri" data-close>Entendido</button></div>`);
  }
  /** Elegir a una persona de la conversación (grupos y canales: quién atiende el ticket o la tarea). */
  function pickMember(c, title, cb) {
    const ppl = (c.members || []).filter(id => id !== S.me).map(id => S.users[id]).filter(u => u && u.active).sort((a, b) => a.name.localeCompare(b.name));
    if (!ppl.length) return toast('No hay otras personas en esta conversación', true);
    dialog(`<div class="cc-dh"><h3>${esc(title)}</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><input class="cc-in" id="cc-pm-q" placeholder="Buscar persona" autocomplete="off"><div class="cc-pm-list" id="cc-pm-l"></div></div>`, (ov, close) => {
      const draw = () => { const q = $('cc-pm-q').value.trim().toLowerCase();
        $('cc-pm-l').innerHTML = ppl.filter(u => !q || (u.name + ' ' + (u.username || '') + ' ' + (u.dept || '')).toLowerCase().includes(q)).map(u => `<button class="cc-row" data-pm="${u.id}">${avatar(u.id, 28)}<span class="cc-rt"><span class="cc-rn"><span>${esc(u.name)}</span></span><span class="cc-rp">${esc(u.dept || u.position || '')}</span></span></button>`).join('') || '<p class="cc-appe">Nadie con ese nombre.</p>'; };
      $('cc-pm-q').addEventListener('input', draw);
      $('cc-pm-q').addEventListener('keydown', e => { if (e.key === 'Enter') { const b = $('cc-pm-l').querySelector('[data-pm]'); if (b) b.click(); } });
      $('cc-pm-l').addEventListener('click', e => { const b = e.target.closest('[data-pm]'); if (b) { close(); cb(+b.dataset.pm); } });
      draw();
    });
  }
  /** /ticket y /tarea: el texto se publica como mensaje y queda enlazado al ticket o la tarea de la otra persona. */
  function quickCreate(cmd, text) {
    const c = curConv(); if (!c) return;
    const a = QA.find(x => x.cmd === cmd);
    if (!text) { const ta = $('cc-ta'); if (ta) { ta.value = '/' + cmd + ' '; ta.focus(); autosize(ta); updateSend(); } return toast('Escribe qué necesitas, por ejemplo: ' + a.ex); }
    if (c.kind === 'dm' && c.peer === S.me) return toast('En tus notas no hay a quién asignarlo: hazlo en un mensaje directo o un grupo', true);
    const go = async assignee => {
      try {
        const d = await api(cmd === 'ticket' ? 'quick_ticket' : 'quick_task', { conv: c.id, body: text, assignee, client_id: newId() });
        upsert(d.message); renderMsgs(); const box = $('cc-msgs'); if (box) box.scrollTop = 1e9;
        const who = uname(d.assignee);
        toast(cmd === 'ticket' ? `Ticket T-${String(d.ticket_id).padStart(4, '0')} abierto · lo atiende ${who}${d.team ? ' (' + d.team + ')' : ''}` : `Tarea creada para ${who}`);
      } catch (e) { fail(e); const ta = $('cc-ta'); if (ta && !ta.value) { ta.value = '/' + cmd + ' ' + text; autosize(ta); updateSend(); } }
    };
    if (c.kind === 'dm') go(0); else pickMember(c, cmd === 'ticket' ? '¿Quién atiende el ticket?' : '¿Para quién es la tarea?', go);
  }
  /** Nota interna en un ticket: "/nota T-0012 texto" va directo; si no, se elige el ticket (los de esta conversación primero). */
  async function noteDlg(text, m) {
    const c = curConv(); if (!c) return;
    const direct = /^T-?(\d+)\s+([\s\S]+)$/i.exec(text || '');
    if (direct && !m) return noteSave(+direct[1], direct[2].trim());
    let d; try { d = await api('note_targets', { conv: c.id }); } catch (e) { return fail(e); }
    const pre = m && m.tickets && m.tickets.length ? +m.tickets[m.tickets.length - 1].id : 0;
    const list = d.tickets || [];
    dialog(`<div class="cc-dh"><h3>Añadir nota a ticket</h3><button class="cc-ib" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cc-db"><div class="cc-fld"><label for="cc-nt-t">Ticket</label>${list.length
        ? `<select class="cc-in" id="cc-nt-t">${list.map(t => `<option value="${t.id}"${t.id === pre ? ' selected' : ''}>${esc(t.code + ' · ' + t.subject)}</option>`).join('')}</select>`
        : '<input class="cc-in" id="cc-nt-t" placeholder="Número del ticket, por ejemplo T-0012" autocomplete="off">'}</div>
      <div class="cc-fld"><label for="cc-nt-b">Nota interna</label><textarea class="cc-in" id="cc-nt-b" maxlength="20000" placeholder="Solo la ve quien atiende el ticket">${esc(text || (m ? m.body || '' : ''))}</textarea></div>
      <p class="cc-appe" style="margin:0">No se publica en el chat: queda en el ticket como nota interna${m ? ', con el enlace a este mensaje' : ''}.</p></div>
      <div class="cc-df"><button class="cc-btn" data-close>Cancelar</button><button class="cc-btn pri" data-go><i class="fa-solid fa-note-sticky"></i> Agregar nota</button></div>`, (ov, close) => {
      ov.querySelector('[data-go]').addEventListener('click', async e => {
        const id = +String($('cc-nt-t').value).replace(/\D/g, ''); if (!id) return toast('Elige el ticket', true);
        e.target.disabled = true;
        if (await noteSave(id, $('cc-nt-b').value.trim(), m)) close(); else e.target.disabled = false;
      });
    });
  }
  async function noteSave(id, body, m) {
    try { const d = await api('ticket_note', { ticket: id, body, message: m ? m.id : 0 }); toast(`Nota agregada a ${d.code}`); return true; }
    catch (e) { fail(e); return false; }
  }

  // ══ Guardados, búsqueda y herramientas (vistas de la lista) ═════
  async function loadSaved() { try { const d = await api('saved_list'); S.saved = d.messages; S.savedCats = d.categories; d.messages.forEach(m => { (S.lookup ||= {})[m.id] = m; }); } catch (e) { S.saved = []; fail(e); } if (S.view === 'saved') renderList(); }
  function savedHtml() {
    if (!S.saved) return '<div class="cc-sk"></div>'.repeat(5);
    const cats = S.savedCats || [];
    const list = S.savedCat ? S.saved.filter(m => m.saved && m.saved.category === S.savedCat) : S.saved;
    const chips = cats.length ? `<div class="cc-chiprow"><button class="${S.savedCat ? '' : 'on'}" data-savedcat="">Todos</button>${cats.map(k => `<button class="${S.savedCat === k ? 'on' : ''}" data-savedcat="${esc(k)}">${esc(k)}</button>`).join('')}</div>` : '';
    if (!list.length) return chips + `<div class="cc-empty"><i class="fa-regular fa-bookmark big"></i><b>Nada guardado</b>Guarda mensajes desde el menú <i class="fa-solid fa-ellipsis-vertical"></i> de cada uno para encontrarlos acá.</div>`;
    return chips + list.map(m => { const c = S.convs[m.conv_id]; return `<div class="cc-card2" data-gomsg="${m.id}">
      <div class="h">${avatar(m.user_id, 22, false)}<b>${esc(uname(m.user_id))}</b><span>${c ? (c.kind === 'channel' ? '#' : '') + esc(convName(c)) : ''} · ${esc(shortWhen(m.created_at))}</span></div>
      <div class="b">${m.deleted ? '<i>Mensaje eliminado</i>' : esc((m.body || (m.poll ? '📊 ' + m.poll.question : '📎 Archivo')).slice(0, 220))}</div>
      ${m.saved && m.saved.note ? `<div class="n"><i class="fa-regular fa-note-sticky"></i> ${esc(m.saved.note)}</div>` : ''}
      <div class="a">${m.saved && m.saved.category ? `<span class="cc-tag">${esc(m.saved.category)}</span>` : ''}<span style="flex:1"></span><button class="cc-btn sm" data-savededit="${m.id}">Nota</button><button class="cc-btn sm" data-savedrm="${m.id}">Quitar</button></div></div>`; }).join('');
  }
  function searchFormHtml() {
    const F = S.search;
    const people = Object.values(S.users).filter(u => u.active).sort((a, b) => a.name.localeCompare(b.name));
    const convs = Object.values(S.convs).filter(c => !c.archived).sort((a, b) => convName(a).localeCompare(convName(b)));
    return `<form class="cc-sform" id="cc-sform">
      <div class="cc-search" style="margin:0 0 8px"><i class="fa-solid fa-magnifying-glass"></i><input id="cc-sq" placeholder="Buscar mensajes, personas, canales y archivos" value="${esc(F.q)}" autocomplete="off" aria-label="Buscar"></div>
      <div class="cc-sf">
        <select class="cc-in" id="cc-s-user" aria-label="De"><option value="0">De: cualquiera</option>${people.map(u => `<option value="${u.id}" ${F.user === u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
        <select class="cc-in" id="cc-s-conv" aria-label="En"><option value="0">En: todas</option>${convs.map(c => `<option value="${c.id}" ${F.conv === c.id ? 'selected' : ''}>${c.kind === 'channel' ? '#' : ''}${esc(convName(c))}</option>`).join('')}</select>
        <select class="cc-in" id="cc-s-team" aria-label="Equipo"><option value="0">Equipo: todos</option>${S.teams.map(t => `<option value="${t.id}" ${F.team === +t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
        <select class="cc-in" id="cc-s-has" aria-label="Contiene"><option value="">Contiene: lo que sea</option>${[['link', 'Enlaces'], ['file', 'Archivos'], ['img', 'Imágenes'], ['docs', 'Documentos'], ['media', 'Audio o video']].map(([k, l]) => `<option value="${k}" ${F.has === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <label><span>Desde</span><input type="date" class="cc-in" id="cc-s-from" value="${esc(F.from)}"></label>
        <label><span>Hasta</span><input type="date" class="cc-in" id="cc-s-to" value="${esc(F.to)}"></label>
      </div>
      <div style="display:flex;gap:8px;margin-top:8px"><button class="cc-btn pri sm" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>${F.q || F.user || F.conv || F.team || F.has || F.from || F.to ? '<button class="cc-btn sm" type="button" data-act="search-clear">Limpiar</button>' : ''}</div></form>`;
  }
  function hl(text, q) {
    let h = esc(String(text || '').slice(0, 260));
    q.split(/\s+/).filter(w => w.length > 1).forEach(w => { h = h.replace(new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/&/g, '&amp;') + ')', 'gi'), '<mark>$1</mark>'); });
    return h;
  }
  function resultsHtml() {
    const R = S.results; if (!R) return `<div class="cc-empty"><i class="fa-solid fa-magnifying-glass big"></i><b>Busca en todo el chat</b>Mensajes, personas, canales y archivos de las conversaciones a las que tienes acceso.</div>`;
    if (R.loading) return '<div class="cc-sk"></div>'.repeat(5);
    let h = '';
    if (R.people.length) h += `<div class="cc-sec-h" style="margin:10px 0 4px">Personas</div>` + R.people.map(id => `<button class="cc-row" data-dm="${id}">${avatar(id, 28)}<span class="cc-rt"><span class="cc-rn"><span>${esc(uname(id))}</span></span><span class="cc-rp">${esc([S.users[id]?.position, S.users[id]?.dept].filter(Boolean).join(' · '))}</span></span></button>`).join('');
    if (R.channels.length) h += `<div class="cc-sec-h" style="margin:10px 0 4px">Canales y grupos</div>` + R.channels.map(c => `<button class="cc-row" ${S.convs[c.id] ? `data-conv="${c.id}"` : `data-peekid="${c.id}"`}><span class="cc-hash" style="--s:28px">${c.kind === 'group' ? '<i class="fa-solid fa-user-group" style="font-size:.8em"></i>' : '#'}</span><span class="cc-rt"><span class="cc-rn"><span>${esc(c.name)}</span></span><span class="cc-rp">${esc(c.topic || '')}</span></span></button>`).join('');
    if (R.files.length) h += `<div class="cc-sec-h" style="margin:10px 0 4px">Archivos</div>` + R.files.map(f => `<a class="cc-afile" href="${esc(f.url)}" target="_blank" rel="noopener"><span class="fi"><i class="fa-solid ${fileIcon(f.mime, f.name)}"></i></span><span style="flex:1;min-width:0"><b>${esc(f.name)}</b><small>${fmtSize(f.size)} · ${esc(uname(f.user_id).split(' ')[0])} · ${shortWhen(f.created_at)}</small></span></a>`).join('');
    if (R.messages.length) h += `<div class="cc-sec-h" style="margin:10px 0 4px">Mensajes</div>` + R.messages.map(m => `<button class="cc-res" data-gomsg="${m.id}"><span class="h">${avatar(m.user_id, 22, false)}<b>${esc(uname(m.user_id))}</b><span>${esc((R.conv_names || {})[m.conv_id] || '')} · ${esc(D(m.created_at).toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: '2-digit' }))}</span></span><span class="b">${hl(m.body || (m.files.length ? '📎 ' + m.files.map(f => f.name).join(', ') : ''), S.search.q)}</span></button>`).join('')
      + (R.more ? `<div class="cc-more"><button class="cc-btn sm" data-act="search-more">Ver más resultados</button></div>` : '');
    return h || `<div class="cc-empty"><i class="fa-solid fa-magnifying-glass big"></i><b>Sin resultados</b>Prueba con otras palabras o saca algún filtro.</div>`;
  }
  async function runSearch(more) {
    const F = S.search;
    if (!more) S.results = { loading: true, people: [], channels: [], files: [], messages: [] };
    renderList();
    try {
      const d = await api('search', { ...F, offset: more ? S.results.messages.length : 0 });
      if (more) { S.results.messages = S.results.messages.concat(d.messages); S.results.more = d.more; Object.assign(S.results.conv_names, d.conv_names || {}); }
      else S.results = { ...d, conv_names: d.conv_names || {} };
      (S.results.messages || []).forEach(m => { (S.lookup ||= {})[m.id] = m; });
    } catch (e) { fail(e); S.results = null; }
    renderList();
  }
  async function loadApps() {
    try { const [r, s, a] = await Promise.all([api('remind_list'), api('sched_list'), api('announcements')]); S.apps = { reminders: r.items, scheduled: s.items, announcements: a.messages }; }
    catch (e) { S.apps = { reminders: [], scheduled: [], announcements: [] }; fail(e); }
    if (S.view === 'apps') renderList();
  }
  function appsHtml() {
    if (!S.apps) return '<div class="cc-sk"></div>'.repeat(5);
    const A = S.apps, cn = id => { const c = S.convs[id]; return c ? (c.kind === 'channel' ? '#' : '') + convName(c) : 'una conversación'; };
    const st = { pending: 'Programado', sent: 'Enviado', cancelled: 'Cancelado', failed: 'No se pudo mandar', sending: 'Enviando' };
    return `<div class="cc-app"><div class="ah"><i class="fa-regular fa-bell"></i><b>Recordatorios</b><button class="cc-btn sm" data-act="app-remind"><i class="fa-solid fa-plus"></i> Nuevo</button></div>
      ${A.reminders.length ? A.reminders.map(r => `<div class="cc-appi${r.done ? ' done' : ''}"><span class="cc-rt"><b>${esc(r.text)}</b><small>${r.done ? 'Avisado' : fmtWhen(r.at)}${r.shared ? ' · para ' + esc(cn(r.conv_id)) : r.conv_id ? ' · ' + esc(cn(r.conv_id)) : ''}</small></span>${r.message_id ? `<button class="cc-ib" data-gomsg="${r.message_id}" title="Ver mensaje" aria-label="Ver mensaje"><i class="fa-solid fa-arrow-up-right-from-square"></i></button>` : ''}<button class="cc-ib" data-remdel="${r.id}" title="Borrar" aria-label="Borrar recordatorio"><i class="fa-regular fa-trash-can"></i></button></div>`).join('') : '<p class="cc-appe">No tienes recordatorios. También puedes crearlos desde el menú de un mensaje.</p>'}</div>
      <div class="cc-app"><div class="ah"><i class="fa-regular fa-clock"></i><b>Mensajes programados</b></div>
      ${A.scheduled.length ? A.scheduled.map(s => `<div class="cc-appi${s.status !== 'pending' ? ' done' : ''}"><span class="cc-rt"><b>${esc(s.body || (s.files ? '📎 Archivos' : 'Anuncio'))}</b><small>${esc(st[s.status] || s.status)} · ${fmtWhen(s.at)} · ${esc(cn(s.conv_id))}${s.error ? ' · ' + esc(s.error) : ''}</small></span>${s.status === 'pending' ? `<button class="cc-ib" data-schedit="${s.id}" title="Editar" aria-label="Editar"><i class="fa-solid fa-pen"></i></button><button class="cc-ib" data-schedx="${s.id}" title="Cancelar" aria-label="Cancelar envío"><i class="fa-solid fa-xmark"></i></button>` : s.message_id ? `<button class="cc-ib" data-gomsg="${s.message_id}" aria-label="Ver mensaje"><i class="fa-solid fa-arrow-up-right-from-square"></i></button>` : ''}</div>`).join('') : '<p class="cc-appe">Para programar, escribe el mensaje y elige <b>+ › Programar este mensaje</b>.</p>'}</div>
      <div class="cc-app"><div class="ah"><i class="fa-solid fa-bullhorn"></i><b>Anuncios</b></div>
      ${A.announcements.length ? A.announcements.map(m => `<button class="cc-appi" data-gomsg="${m.id}" style="width:100%;text-align:left;border:0;background:none"><span class="cc-rt"><b>${esc(m.ann ? m.ann.title : '')}</b><small>${esc(uname(m.user_id))} · ${esc(cn(m.conv_id))} · ${shortWhen(m.created_at)}</small></span></button>`).join('') : '<p class="cc-appe">Los administradores de un canal publican anuncios desde <b>+ › Anuncio destacado</b>.</p>'}</div>
      <div class="cc-app"><div class="ah"><i class="fa-solid fa-list-check"></i><b>Tareas</b></div><p class="cc-appe">Cualquier mensaje se convierte en tarea (en el tablero que elijas) o en un ticket desde su menú <i class="fa-solid fa-ellipsis-vertical"></i>. ${S.canTasks ? '<a href="/toolz/trabajo/" target="_blank" rel="noopener">Abrir Gestión de trabajo</a>' : ''}</p></div>
      <div class="cc-app"><div class="ah"><i class="fa-solid fa-square-poll-vertical"></i><b>Encuestas</b></div><p class="cc-appe">Dentro de cualquier conversación: <b>+ › Encuesta</b>.</p></div>`;
  }
  function settingsHtml() {
    const p = S.prefs, perm = 'Notification' in window ? Notification.permission : 'unsupported';
    const tg = (k, l, d) => `<label class="cc-set"><span><b>${l}</b>${d ? `<small>${d}</small>` : ''}</span><input type="checkbox" data-pref="${k}" ${p[k] ? 'checked' : ''}></label>`;
    return `<div class="cc-app"><div class="ah"><i class="fa-regular fa-bell"></i><b>Notificaciones</b></div>
      ${perm === 'granted' ? '' : perm === 'denied' ? '<p class="cc-appe">El navegador tiene bloqueados los avisos de este sitio: habilitalos desde el candado de la barra de direcciones.</p>' : perm === 'unsupported' ? '<p class="cc-appe">Este navegador no muestra notificaciones.</p>' : '<button class="cc-btn pri sm" data-act="notif-perm" style="margin:4px 0 8px"><i class="fa-regular fa-bell"></i> Permitir avisos del navegador</button>'}
      ${tg('browser', 'Avisos del navegador', 'Cuando el chat no está a la vista')}${tg('sound', 'Sonido', '')}
      <div class="cc-subh">Avisarme de todos los mensajes en…</div>${tg('dm', 'Mensajes directos', '')}${tg('groups', 'Grupos', '')}${tg('channels', 'Canales', 'Si está apagado, solo te avisan las menciones')}
      <p class="cc-appe">Cada conversación tiene además su propio nivel de aviso en <b>Detalles</b>.</p></div>
      <div class="cc-app"><div class="ah"><i class="fa-regular fa-moon"></i><b>Horario de descanso</b></div>${tg('quiet_on', 'Silenciar avisos en este horario', 'Los mensajes llegan igual; solo no suenan ni aparecen avisos')}
      <div class="cc-time"><label>De <input type="time" class="cc-in" data-pref="quiet_from" value="${esc(p.quiet_from)}"></label><label>a <input type="time" class="cc-in" data-pref="quiet_to" value="${esc(p.quiet_to)}"></label></div></div>
      <div class="cc-app"><div class="ah"><i class="fa-regular fa-user"></i><b>Perfil y estado</b></div><button class="cc-btn sm" data-act="status-menu">Cambiar mi estado</button> <a class="cc-btn sm" href="/toolz/perfil.php" target="_blank" rel="noopener"><i class="fa-solid fa-id-card"></i> Ver mi perfil</a> <a class="cc-btn sm" href="/toolz/comunidad.php?editar=1" target="_blank" rel="noopener">Editar mi perfil</a>
      <p class="cc-appe">Foto, cargo y datos de contacto se editan en tu perfil de la intranet.</p></div>
      <div class="cc-app"><div class="ah"><i class="fa-solid fa-check-double"></i><b>Mensajes</b></div><button class="cc-btn sm" data-act="read-all">Marcar todo como leído</button></div>
      <div class="cc-app"><div class="ah"><i class="fa-regular fa-keyboard"></i><b>Atajos</b></div><dl class="cc-keys"><dt>Ctrl K</dt><dd>Buscar conversación</dd><dt>Ctrl Shift F</dt><dd>Búsqueda avanzada</dd><dt>Enter</dt><dd>Enviar</dd><dt>Shift Enter</dt><dd>Nueva línea</dd><dt>↑</dt><dd>Editar tu último mensaje</dd><dt>Ctrl B / I</dt><dd>Negrita / cursiva</dd><dt>Esc</dt><dd>Cerrar paneles</dd></dl></div>`;
  }
  async function savePref(k, v) {
    S.prefs[k] = v;
    try { const d = await api('prefs', { prefs: { [k]: v } }); S.prefs = d.prefs; } catch (e) { fail(e); }
  }

  // ══ Formato y menciones al escribir ════════════════════════════
  function wrapSel(ta, before, after = before, ph = 'texto') {
    const a = ta.selectionStart, b = ta.selectionEnd, sel = ta.value.slice(a, b) || ph;
    ta.value = ta.value.slice(0, a) + before + sel + after + ta.value.slice(b);
    ta.focus(); ta.setSelectionRange(a + before.length, a + before.length + sel.length);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function prefixLines(ta, mk) {
    const a = ta.value.lastIndexOf('\n', ta.selectionStart - 1) + 1, b = ta.selectionEnd;
    const block = ta.value.slice(a, b) || '';
    const out = (block || 'elemento').split('\n').map((l, i) => (typeof mk === 'function' ? mk(i) : mk) + l).join('\n');
    ta.value = ta.value.slice(0, a) + out + ta.value.slice(b);
    ta.focus(); ta.setSelectionRange(a, a + out.length); ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function applyFmt(kind, ta) {
    ta = ta || $('cc-ta'); if (!ta) return;
    if (kind === 'b') wrapSel(ta, '**'); else if (kind === 'i') wrapSel(ta, '_'); else if (kind === 's') wrapSel(ta, '~'); else if (kind === 'u') wrapSel(ta, '__');
    else if (kind === 'code') wrapSel(ta, '`', '`', 'código'); else if (kind === 'pre') wrapSel(ta, '```\n', '\n```', 'código');
    else if (kind === 'ul') prefixLines(ta, '- '); else if (kind === 'ol') prefixLines(ta, i => (i + 1) + '. '); else if (kind === 'q') prefixLines(ta, '> ');
    else if (kind === 'link') {
      const url = prompt('Dirección del enlace (https://…)'); if (!url) return;
      if (!/^https?:\/\//i.test(url)) return toast('El enlace tiene que empezar con http:// o https://', true);
      wrapSel(ta, '[', `](${url})`, 'texto del enlace');
    }
  }
  let mentionBox = null, mentionState = null;
  function mentionCheck(ta) {
    const before = ta.value.slice(0, ta.selectionStart);
    // Acciones rápidas: "/" al principio del mensaje (solo en el escritor principal)
    const sl = ta.id === 'cc-ta' ? /^\/([a-zñ]*)$/i.exec(before) : null;
    if (sl) {
      const q = sl[1].toLowerCase();
      const opts = qaList().filter(a => a.cmd.startsWith(q) || norm(a.label).includes(norm(q))).map(a => ({ cmd: a.cmd, name: '/' + a.cmd, sub: a.label + ' · ' + a.sub, icon: a.icon }));
      if (!opts.length) return mentionClose();
      return mentionShow(ta, { ta, start: 0, opts, q, i: Math.min(mentionState && mentionState.ta === ta ? mentionState.i : 0, opts.length - 1) });
    }
    const m = before.match(/(^|\s)@([\p{L}\p{N}_.\-]*)$/u);
    const c = curConv() && S.convs[curConv().id];
    if (!m || !c) return mentionClose();
    const q = m[2].toLowerCase();
    let opts = (c.members || []).filter(id => id !== S.me).map(id => S.users[id]).filter(u => u && u.active && (u.name.toLowerCase().includes(q) || (u.username || '').toLowerCase().includes(q)));
    opts = opts.slice(0, 8).map(u => ({ id: u.id, name: u.name, word: u.username, sub: u.position || u.dept || '@' + u.username }));
    if (c.kind !== 'dm' && 'todos'.startsWith(q)) opts.push({ id: 0, name: 'todos', word: 'todos', sub: 'Avisa a todos los miembros' });
    // Clientes y tickets (chat/refs_lib.php): se buscan aparte y se suman abajo; al elegir uno queda el vínculo [[tipo:id]]
    const refs = (MREF[q] || []).map(r => ({ ref: r.kind + ':' + r.id, name: r.title, sub: r.label + (r.code ? ' · ' + r.code : '') + (r.sub ? ' · ' + r.sub : ''), icon: r.icon, color: r.color }));
    opts = opts.concat(refs);
    if (!(q in MREF)) mentionRefs(ta, q);
    if (!opts.length) return mentionClose();
    mentionShow(ta, { ta, start: before.length - m[2].length - 1, opts, q, i: Math.min(mentionState && mentionState.ta === ta ? mentionState.i : 0, opts.length - 1) });
  }
  const norm = t => String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  function mentionShow(ta, st) {
    mentionState = st;
    if (!mentionBox) { mentionBox = document.createElement('div'); mentionBox.className = 'cc-mention'; mentionBox.setAttribute('role', 'listbox'); document.body.appendChild(mentionBox); mentionBox.addEventListener('mousedown', e => { const b = e.target.closest('[data-mi]'); if (b) { e.preventDefault(); mentionPick(+b.dataset.mi); } }); }
    mentionBox.innerHTML = mentionState.opts.map((o, i) => `<button role="option" aria-selected="${i === mentionState.i}" class="${i === mentionState.i ? 'on' : ''}" data-mi="${i}">${o.cmd ? `<span class="cc-hash cc-qa-ic" style="--s:24px"><i class="fa-solid ${esc(o.icon)}"></i></span>` : o.ref ? `<span class="cc-hash" style="--s:24px;color:${esc(o.color)}"><i class="fa-solid ${esc(o.icon)}"></i></span>` : o.id ? avatar(o.id, 24) : '<span class="cc-hash" style="--s:24px">@</span>'}<span><b>${esc(o.name)}</b><small>${esc(o.sub)}</small></span></button>`).join('');
    const r = ta.closest('.cc-box').getBoundingClientRect();
    mentionBox.style.left = r.left + 'px'; mentionBox.style.width = Math.min(380, r.width) + 'px';
    mentionBox.style.bottom = (innerHeight - r.top + 6) + 'px';
  }
  // Resultados de clientes y tickets por texto buscado (caché del rato; el servidor solo devuelve lo que esta persona puede ver)
  const MREF = {}; let mrefT = null;
  function mentionRefs(ta, q) {
    clearTimeout(mrefT);
    mrefT = setTimeout(() => {
      api('ref_search', { q, only: ['cliente', 'ticket', 'jira'] }).then(d => { MREF[q] = (d.items || []).slice(0, 6); if (document.activeElement === ta) mentionCheck(ta); }).catch(() => { MREF[q] = []; });
    }, 180);
  }
  function mentionClose() { if (mentionBox) { mentionBox.remove(); mentionBox = null; } mentionState = null; }
  function mentionPick(i) {
    const M = mentionState; if (!M) return;
    const o = M.opts[i], ta = M.ta, end = ta.selectionStart;
    const ins = o.cmd ? '/' + o.cmd + ' ' : o.ref ? '[[' + o.ref + ']] ' : '@' + o.word + ' ';
    ta.value = ta.value.slice(0, M.start) + ins + ta.value.slice(end);
    const p = M.start + ins.length; ta.setSelectionRange(p, p); ta.focus();
    mentionClose(); ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // ══ Orden de canales y categorías arrastrando (admins) ══════════
  function setupDnD() {
    if (!S.admin || S.view !== 'home' || S.q) return;
    const sc = list.querySelector('.cc-scroll'); if (!sc) return;
    sc.querySelectorAll('.cc-sec[data-sec^="cat"], .cc-sec[data-sec="chans"]').forEach(sec => {
      const h = sec.querySelector('.cc-sec-h'); if (sec.dataset.sec !== 'chans') { h.draggable = true; h.title = 'Arrastra para ordenar las categorías'; }
      sec.querySelectorAll('.cc-rows > .cc-row[data-conv]').forEach(r => { if (S.convs[+r.dataset.conv]?.kind === 'channel') r.draggable = true; });
    });
    let drag = null;
    sc.addEventListener('dragstart', e => {
      const row = e.target.closest('.cc-row[data-conv]'), head = e.target.closest('.cc-sec-h');
      drag = row ? { type: 'ch', el: row } : head ? { type: 'cat', el: head.closest('.cc-sec') } : null;
      if (!drag) return;
      e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'x');
      setTimeout(() => drag && drag.el.classList.add('dragging'), 0);
    });
    sc.addEventListener('dragover', e => {
      if (!drag) return;
      e.preventDefault();
      if (drag.type === 'ch') {
        const rows = e.target.closest('.cc-sec[data-sec^="cat"], .cc-sec[data-sec="chans"]')?.querySelector('.cc-rows'); if (!rows) return;
        const after = [...rows.querySelectorAll('.cc-row[data-conv]:not(.dragging)')].find(r => e.clientY < r.getBoundingClientRect().top + r.offsetHeight / 2);
        after ? rows.insertBefore(drag.el, after) : rows.insertBefore(drag.el, rows.querySelector('.cc-row[data-act]') || null);
      } else {
        const sec = e.target.closest('.cc-sec[data-sec^="cat"]'); if (!sec || sec === drag.el) return;
        const r = sec.getBoundingClientRect(); e.clientY < r.top + r.height / 2 ? sec.before(drag.el) : sec.after(drag.el);
      }
    });
    sc.addEventListener('dragend', async () => {
      if (!drag) return;
      drag.el.classList.remove('dragging'); drag = null;
      const cats = [...sc.querySelectorAll('.cc-sec[data-sec^="cat"]')].map(s => +s.dataset.sec.slice(3));
      const channels = [...sc.querySelectorAll('.cc-sec[data-sec^="cat"], .cc-sec[data-sec="chans"]')].map(s => ({ category_id: s.dataset.sec === 'chans' ? 0 : +s.dataset.sec.slice(3), ids: [...s.querySelectorAll('.cc-row[data-conv]')].map(r => +r.dataset.conv) }));
      // Estado local al instante; el servidor confirma
      cats.forEach((id, i) => { const k = S.cats.find(x => +x.id === id); if (k) k.sort = i; });
      channels.forEach(g => g.ids.forEach((id, i) => { const c = S.convs[id]; if (c) { c.sort = i; c.category_id = g.category_id || null; } }));
      renderList();
      try { await api('order', { categories: cats, channels }); toast('Orden guardado'); } catch (e) { fail(e); }
    });
  }

  // ── Eventos ──
  // Hora local al pasar el mouse por el nombre o el avatar de alguien (o por la píldora de la cabecera). Solo con mouse: en el
  // celular la hora está en la cabecera y en la ficha que se abre al tocar.
  (function tzHover() {
    let tip = null, timer = 0, cur = null;
    const hide = () => { clearTimeout(timer); cur = null; if (tip) { tip.remove(); tip = null; } };
    document.addEventListener('mouseover', e => {
      if (!TZ || !e.target.closest) return;
      const t = e.target.closest('.cc [data-user], .cc [data-tzuser]');
      if (!t || t.closest('.cc-card')) { if (cur && !(e.target.closest && e.target.closest('.cc-tzt'))) hide(); return; }
      if (t === cur) return;
      hide(); cur = t;
      const uid = +(t.dataset.user || t.dataset.tzuser); if (!S.users[uid]) return;
      timer = setTimeout(() => {
        if (cur !== t || !document.contains(t)) return;
        tip = document.createElement('div'); tip.className = 'cc-tzt'; tip.setAttribute('role', 'tooltip'); tip.innerHTML = tzTipHtml(uid);
        document.body.appendChild(tip);
        const r = t.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
        let x = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), innerWidth - w - 8), y = r.bottom + 8;
        if (y + h > innerHeight - 8) y = r.top - h - 8;
        tip.style.left = x + 'px'; tip.style.top = y + 'px';
      }, 380);
    });
    document.addEventListener('mouseout', e => { if (cur && !(e.relatedTarget && cur.contains(e.relatedTarget))) hide(); });
    document.addEventListener('scroll', hide, true);
    document.addEventListener('mousedown', hide);
  })();

  document.addEventListener('click', async e => {
    const t = e.target;
    if (mentionBox && !t.closest('.cc-mention')) mentionClose();
    if (!t.closest('.cc-menu,.cc-emo,.cc-card')) {
      const keep = t.closest('[data-act="react-more"],[data-act="emoji"],[data-user],#cc-me,[data-memmenu],[data-act="new-menu"],[data-act="more"],[data-act="comp-plus"],[data-act="status-menu"]');
      if (!keep) closeMenus();
    }
    const rb = t.closest('.cc-rb[data-view]');
    if (rb) {
      S.view = rb.dataset.view; S.q = ''; setRail();
      if (S.view === 'channels' && !S.browse) loadBrowse();
      if (S.view === 'files') { S.files = null; loadFiles(); }
      if (S.view === 'activity') loadNotifs();
      if (S.view === 'saved') loadSaved();
      if (S.view === 'apps') loadApps();
      cc.classList.remove('open'); renderList();
      if (S.view === 'search') $('cc-sq')?.focus();
      return;
    }
    if (t.closest('#cc-me')) { const r = t.closest('#cc-me').getBoundingClientRect(); statusMenu(r.right + 8, r.bottom - 300); return; }
    const sec = t.closest('[data-toggle]');
    if (sec && !t.closest('[data-act]')) { const k = sec.dataset.toggle; S.collapsed[k] = !S.collapsed[k]; LS.set('collapsed', S.collapsed); renderList(); return; }
    const u = t.closest('[data-user]');
    if (u && t.closest('.cc-msgs,.cc-aside,.cc-mh,.cc-people')) { const r = u.getBoundingClientRect(); profileCard(+u.dataset.user, r.right + 8, r.top); return; }
    const conv = t.closest('[data-conv]'); if (conv) { if (S.view === 'files') return; openConv(+conv.dataset.conv); return; }
    const dm = t.closest('[data-dm]'); if (dm) { S.q = ''; openDM(+dm.dataset.dm); return; }
    const jn = t.closest('[data-join]'); if (jn) { join(+jn.dataset.join); return; }
    const pk = t.closest('[data-peek]'); if (pk) { const c = S.browse.find(x => x.id === +pk.dataset.peek); openConv(c.id, { preview: { ...c, kind: 'channel', members: c.members } }); return; }
    const fk = t.closest('[data-fkind]'); if (fk) { S.filesKind = fk.dataset.fkind; S.files = null; renderList(); loadFiles(); return; }
    const tab = t.closest('[data-tab]'); if (tab) { S.asideTab = tab.dataset.tab; renderAside(); return; }
    const img = t.closest('[data-img]'); if (img) { lightbox(img.dataset.img, img.dataset.name); return; }
    const gq = t.closest('[data-goto]'); if (gq) { const el = $('m-' + gq.dataset.goto); if (el) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.classList.remove('hl'); void el.offsetWidth; el.classList.add('hl'); } else openConv(S.open, { around: +gq.dataset.goto }); return; }
    const rm = t.closest('[data-rmatt]'); if (rm) { S.att = S.att.filter(a => a.tmp !== rm.dataset.rmatt); renderAtt(); return; }
    const mm = t.closest('[data-memmenu]');
    if (mm) {
      const who = +mm.dataset.memmenu, role = mm.dataset.role, c = curConv(), r = mm.getBoundingClientRect();
      const items = [];
      if (S.info.is_owner || (c.kind === 'channel' && S.admin)) items.push(role === 'admin' ? { label: 'Quitar como administrador', icon: 'fa-solid fa-user-minus', run: () => setRole(who, 'member') } : { label: 'Hacer administrador', icon: 'fa-solid fa-user-shield', run: () => setRole(who, 'admin') });
      items.push({ label: 'Enviar mensaje', icon: 'fa-regular fa-paper-plane', run: () => openDM(who) });
      items.push({ label: c.kind === 'channel' ? 'Sacar del canal' : 'Sacar del grupo', icon: 'fa-solid fa-user-xmark', red: true, run: () => removeMember(who) });
      menu(items, r.left - 160, r.bottom + 4); return;
    }
    const rx = t.closest('[data-react]'); if (rx) { const m = t.closest('.cc-m'); if (m) react(+m.dataset.id, rx.dataset.react); return; }
    const nt = t.closest('[data-notif]'); if (nt) { const n = (S.notifs || []).find(x => x.id === +nt.dataset.notif); if (n) openNotif(n); return; }
    const nf = t.closest('[data-nfilter]'); if (nf) { S.notifFilter = nf.dataset.nfilter; renderList(); return; }
    const scat = t.closest('[data-savedcat]'); if (scat) { S.savedCat = scat.dataset.savedcat; renderList(); return; }
    const sed = t.closest('[data-savededit]'); if (sed) { const m = (S.saved || []).find(x => x.id === +sed.dataset.savededit); if (m) saveDlg(m); return; }
    const srm = t.closest('[data-savedrm]'); if (srm) { const m = (S.saved || []).find(x => x.id === +srm.dataset.savedrm); if (m) saveMsg(m, false); return; }
    const rdel = t.closest('[data-remdel]'); if (rdel) { try { await api('remind_delete', { id: +rdel.dataset.remdel }); loadApps(); toast('Recordatorio borrado'); } catch (err) { fail(err); } return; }
    const sx = t.closest('[data-schedx]'); if (sx) { if (await confirmDlg('¿Cancelar el envío?', 'El mensaje no se va a publicar.', 'Cancelar envío', true)) { try { await api('sched_cancel', { id: +sx.dataset.schedx }); loadApps(); toast('Envío cancelado'); } catch (err) { fail(err); } } return; }
    const se = t.closest('[data-schedit]'); if (se) { const it = S.apps.scheduled.find(x => x.id === +se.dataset.schedit); if (it) schedDlg(it); return; }
    const pr = t.closest('[data-preset]'); if (pr) { const inp = $(pr.dataset.for); if (inp) inp.value = pr.dataset.preset; return; }
    const fm = t.closest('[data-fmt]'); if (fm) { applyFmt(fm.dataset.fmt); return; }
    const po = t.closest('[data-pollopt]'); if (po) { const mm = t.closest('.cc-m'); const m = mm && msgById(+mm.dataset.id); if (m) votePoll(m, +po.dataset.pollopt); return; }
    const gm = t.closest('[data-gomsg]'); if (gm && !t.closest('a,button:not([data-gomsg])')) { const id = +gm.dataset.gomsg; if (S.thread && S.aside === 'thread' && t.closest('.cc-aside')) S.aside = null; gotoMessage(id); return; }
    const pk2 = t.closest('[data-peekid]'); if (pk2) { if (!S.browse) await loadBrowse(); const c = (S.browse || []).find(x => x.id === +pk2.dataset.peekid); if (c) openConv(c.id, { preview: { ...c, kind: 'channel' } }); return; }
    const a = t.closest('[data-act]');
    // En pantallas táctiles, tocar un mensaje muestra sus acciones
    if (!a && matchMedia('(hover:none)').matches) { const mm2 = t.closest('.cc-m:not(.sys)'); if (mm2 && !t.closest('a,button,video,audio')) { const on = mm2.classList.contains('acts-on'); document.querySelectorAll('.cc-m.acts-on').forEach(x => x.classList.remove('acts-on')); mm2.classList.toggle('acts-on', !on); } }
    if (!a) return;
    const act = a.dataset.act, mEl = a.closest('.cc-m'), mid = mEl ? (mEl.dataset.id.startsWith('p') ? mEl.dataset.id : +mEl.dataset.id) : null;
    switch (act) {
      case 'thread': { const id = +mEl.dataset.id; const m = msgById(id); if (m) openThread(m.parent_id || id); break; }
      case 'more': { const m = msgById(mid); if (m) { const r = a.getBoundingClientRect(); mEl.classList.add('acts-on'); moreMenu(m, r.left - 200, r.bottom + 4); } break; }
      case 'follow': { const T = S.thread; if (!T) break; try { const d = await api('thread_follow', { id: T.root.id, follow: !T.following }); T.following = d.following; renderThread(); toast(d.following ? 'Vas a recibir avisos de este hilo' : 'Ya no sigues este hilo'); } catch (err) { fail(err); } break; }
      case 'thread-send': sendThread(); break;
      case 'poll-close': { const m = msgById(mid); if (!m) break; try { const d = await api('poll_close', { id: m.id, reopen: m.poll.closed }); upsert(d.message); renderMsgs(); } catch (err) { fail(err); } break; }
      case 'unfurl-hide': { try { const d = await api('unfurl_hide', { id: mid }); upsert(d.message); renderMsgs(); } catch (err) { fail(err); } break; }
      case 'comp-quick': { const r = a.getBoundingClientRect(); qaMenu(r.left, r.top - 290); break; }
      case 'comp-plus': { const r = a.getBoundingClientRect(); plusMenu(r.left, r.top - 170); break; }
      case 'fmt-toggle': { const b = $('cc-fmtbar'); b.hidden = !b.hidden; a.classList.toggle('on', !b.hidden); LS.set('fmtbar', !b.hidden); $('cc-ta')?.focus(); break; }
      case 'notif-more': { try { const d = await api('notifs', { before: S.notifs[S.notifs.length - 1].id }); S.notifs = S.notifs.concat(d.items); S.notifMore = d.more; renderList(); } catch (err) { fail(err); } break; }
      case 'notif-allread': { try { await api('notif_read', { all: 1 }); S.notifs.forEach(n => { n.read = true; }); S.notifUnread = 0; updateBadges(); renderList(); } catch (err) { fail(err); } break; }
      case 'notif-perm': { try { await Notification.requestPermission(); } catch (err) {} renderList(); break; }
      case 'read-all': { if (!(await confirmDlg('¿Marcar todo como leído?', 'Se ponen en cero los contadores de todas tus conversaciones y la actividad.', 'Marcar todo'))) break; try { const d = await api('read_all'); d.convs.forEach(c => { S.convs[c.id] = c; }); S.notifUnread = 0; (S.notifs || []).forEach(n => { n.read = true; }); updateBadges(); renderList(); toast('Todo al día'); } catch (err) { fail(err); } break; }
      case 'status-menu': { const r = a.getBoundingClientRect(); statusMenu(r.left, r.bottom + 4); break; }
      case 'search-clear': S.search = { q: '', user: 0, conv: 0, team: 0, from: '', to: '', has: '' }; S.results = null; renderList(); break;
      case 'search-more': runSearch(true); break;
      case 'app-remind': remindDlg({}); break;
      case 'new-dm': newDM(); break;
      case 'new-group': newGroup(); break;
      case 'new-channel': newChannel(); break;
      case 'new-menu': { const r = a.getBoundingClientRect(); menu([{ label: 'Mensaje directo', icon: 'fa-regular fa-paper-plane', run: newDM }, { label: 'Grupo', icon: 'fa-solid fa-user-group', run: () => newGroup() }, { label: 'Canal', icon: 'fa-solid fa-hashtag', run: newChannel }], r.left, r.bottom + 6); break; }
      case 'browse': S.view = 'channels'; setRail(); cc.classList.remove('open'); if (!S.browse) loadBrowse(); renderList(); break;
      case 'back': closeConv(); break;
      case 'info': if (S.aside === 'thread') { S.aside = null; S.thread = null; } toggleAside('info'); break;
      case 'aside-close': S.aside = null; S.thread = null; cc.classList.remove('aside-on'); renderHead(); break;
      case 'fav': pref('favorite'); break;
      case 'mute': pref('muted'); break;
      case 'older': loadOlder(); break;
      case 'newer': loadNewer(); break;
      case 'attach': $('cc-file').click(); break;
      case 'send': send(); break;
      case 'ref-link': { if (window.CCRefs) CCRefs.picker(a, api, tok => { CCRefs.insert($('cc-ta'), tok); updateSend(); }); break; }
      case 'emoji': { const r = a.getBoundingClientRect(); emojiPicker(r.right - 330, r.top - 370, em => { const ta = $('cc-ta'); const p = ta.selectionStart ?? ta.value.length; ta.value = ta.value.slice(0, p) + em + ta.value.slice(ta.selectionEnd ?? p); ta.focus(); ta.setSelectionRange(p + em.length, p + em.length); updateSend(); }); break; }
      case 'react-more': { const r = a.getBoundingClientRect(); mEl.classList.add('acts-on'); emojiPicker(r.left - 150, r.bottom + 6, em => react(mid, em)); break; }
      case 'reply': { const m = msgById(mid); if (m) { S.replyTo = m; renderReplyBar(); $('cc-ta')?.focus(); } break; }
      case 'reply-cancel': S.replyTo = null; renderReplyBar(); break;
      case 'copy': { const m = msgById(mid); try { await navigator.clipboard.writeText(m.body || ''); toast('Texto copiado'); } catch (err) { toast('No se pudo copiar', true); } break; }
      case 'edit': startEdit(mid); break;
      case 'edit-save': saveEdit(); break;
      case 'edit-cancel': S.editing = null; renderMsgs(); break;
      case 'delete': { const m = msgById(mid); if (m) askDelete(m); break; }
      case 'retry': { const m = msgById(mid); if (m) { m.failed = false; m.pending = true; renderMsgs(); deliver(m); } break; }
      case 'discard': { const C = S.conv[S.open]; C.list = C.list.filter(x => x.id !== mid); renderMsgs(); break; }
      case 'add-members': addMembers(); break;
      case 'avatar': pickAvatar(false); break;
      case 'avatar-rm': pickAvatar(true); break;
      case 'leave': {
        const c = curConv();
        if (!(await confirmDlg(c.kind === 'channel' ? `¿Salir de #${c.name}?` : `¿Salir de «${c.name}»?`, c.kind === 'channel' && c.visibility === 'public' ? 'Puedes volver a unirte cuando quieras desde Canales.' : 'Para volver, alguien del grupo te tiene que sumar.', 'Salir', true))) break;
        try { await api('leave', { conv: c.id }); delete S.convs[c.id]; closeConv(); toast('Saliste de la conversación'); } catch (err) { fail(err); }
        break;
      }
      case 'conv-delete': {
        const c = curConv();
        if (!(await confirmDlg(c.kind === 'channel' ? `¿Borrar #${c.name}?` : `¿Borrar «${c.name}»?`, 'Desaparece para todos sus miembros. Esta acción no se puede deshacer.', 'Borrar', true))) break;
        try { await api('conv_delete', { conv: c.id }); delete S.convs[c.id]; S.browse = null; closeConv(); toast('Conversación borrada'); } catch (err) { fail(err); }
        break;
      }
    }
  });
  async function setRole(uid, role) { try { await api('member_role', { conv: S.open, user_id: uid, role }); loadInfo(); toast(role === 'admin' ? 'Ahora es administrador' : 'Ya no es administrador'); } catch (e) { fail(e); } }
  async function removeMember(uid) {
    if (!(await confirmDlg(`¿Sacar a ${uname(uid)}?`, 'Deja de ver esta conversación. La puedes volver a sumar más adelante.', 'Sacar', true))) return;
    try { await api('member_remove', { conv: S.open, user_id: uid }); loadInfo(); toast('Listo'); } catch (e) { fail(e); }
  }

  document.addEventListener('submit', async e => {
    if (e.target.id === 'cc-sform') {
      e.preventDefault();
      S.search = { q: $('cc-sq').value.trim(), user: +$('cc-s-user').value, conv: +$('cc-s-conv').value, team: +$('cc-s-team').value, has: $('cc-s-has').value, from: $('cc-s-from').value, to: $('cc-s-to').value };
      if (!S.search.q && !S.search.user && !S.search.conv && !S.search.team && !S.search.has && !S.search.from && !S.search.to) return toast('Escribe algo o elige un filtro', true);
      runSearch(); return;
    }
    if (e.target.id !== 'cc-infoform') return;
    e.preventDefault();
    const c = curConv(), f = e.target;
    const data = { conv: c.id, name: $('cc-f-name').value, topic: $('cc-f-topic').value };
    if (c.kind === 'channel') {
      data.category_id = +$('cc-f-cat').value; data.visibility = f.querySelector('input[name=cc-f-vis]:checked').value;
      data.allowed_teams = [...f.querySelectorAll('input[name=cc-f-team]:checked')].map(i => +i.value); data.announce = $('cc-f-ann').checked;
      if (data.visibility === 'restricted' && !data.allowed_teams.length) return toast('Elige qué equipos pueden entrar', true);
    }
    const btn = f.querySelector('[type=submit]'); btn.disabled = true;
    try { const d = await api('conv_update', data); if (d.conv) S.convs[c.id] = d.conv; renderHead(); renderList(); toast('Cambios guardados'); loadInfo(); } catch (err) { fail(err); } finally { btn.disabled = false; }
  });
  document.addEventListener('change', async e => {
    const pk = e.target.dataset && e.target.dataset.pref;
    if (pk) { savePref(pk, e.target.type === 'checkbox' ? e.target.checked : e.target.value); return; }
    if (e.target.matches && e.target.matches('[data-notify-level]')) {
      try { const d = await api('notify_level', { conv: S.open, level: e.target.value }); S.convs[S.open] = d.conv; toast('Listo'); } catch (err) { fail(err); }
      return;
    }
    if (e.target.name === 'cc-f-vis') { const w = $('cc-f-teams-w'); if (w) w.hidden = e.target.value !== 'restricted'; }
    if (e.target.id === 'cc-file') { addFiles(e.target.files); e.target.value = ''; }
  });
  document.addEventListener('input', e => {
    if (e.target.id === 'cc-q') { S.q = e.target.value.trim().toLowerCase(); renderList(); }
    if (e.target.id === 'cc-fq') { S.fq = e.target.value.trim(); S.fqFocus = true; clearTimeout(filesT); filesT = setTimeout(loadFiles, 250); }
    if (e.target.id === 'cc-ta' || e.target.id === 'cc-tta') mentionCheck(e.target);
    if (e.target.id === 'cc-tta') { autosize(e.target); if (S.thread) S.thread.draft = e.target.value; }
    if (e.target.id === 'cc-ta') {
      autosize(e.target); updateSend();
      S.draft = e.target.value; LS.set('draft.' + S.open, S.draft);
      if (e.target.value && Date.now() - typingAt > 3000 && S.convs[S.open]) { typingAt = Date.now(); api('typing', { conv: S.open }).catch(() => {}); }
    }
    if (e.target.id === 'cc-edit') autosize(e.target);
  });
  // Ctrl+K: en el chat busca conversaciones (gana al buscador de herramientas de la barra de arriba)
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'f') { e.preventDefault(); S.view = 'search'; setRail(); cc.classList.remove('open'); renderList(); $('cc-sq')?.focus(); return; }
    if (!((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) return;
    e.preventDefault(); e.stopImmediatePropagation();
    if (S.view === 'channels' || S.view === 'files') { S.view = 'home'; setRail(); }
    cc.classList.remove('open'); renderList(); const q = $('cc-q'); q.focus(); q.select();
  }, true);
  document.addEventListener('keydown', e => {
    if (e.target.id === 'cc-q') {
      if (e.key === 'Escape') { S.q = ''; e.target.value = ''; renderList(); }
      if (e.key === 'Enter') { const first = list.querySelector('.cc-scroll [data-conv], .cc-scroll [data-dm]'); if (first) { S.q = ''; first.click(); } }
      return;
    }
    if ((e.target.id === 'cc-ta' || e.target.id === 'cc-tta') && mentionState) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); mentionState.i = (mentionState.i + (e.key === 'ArrowDown' ? 1 : -1) + mentionState.opts.length) % mentionState.opts.length; mentionCheck(e.target); return; }
      if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); mentionPick(mentionState.i); return; }
      if (e.key === 'Escape') { mentionClose(); return; }
    }
    if ((e.target.id === 'cc-ta' || e.target.id === 'cc-tta') && (e.ctrlKey || e.metaKey) && ['b', 'i'].includes(e.key.toLowerCase())) { e.preventDefault(); applyFmt(e.key.toLowerCase(), e.target); return; }
    if (e.target.id === 'cc-tta') { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); sendThread(); } return; }
    if (e.target.id === 'cc-ta') {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
      else if (e.key === 'Escape' && S.replyTo) { S.replyTo = null; renderReplyBar(); }
      else if (e.key === 'ArrowUp' && !e.target.value) {
        const C = S.conv[S.open]; const m = C && [...C.list].reverse().find(x => x.user_id === S.me && !x.deleted && !x.pending && x.kind !== 'system');
        if (m) { e.preventDefault(); startEdit(m.id); }
      }
      return;
    }
    if (e.target.id === 'cc-edit') {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveEdit(); }
      if (e.key === 'Escape') { S.editing = null; renderMsgs(); $('cc-ta')?.focus(); }
      return;
    }
    if (e.key === 'Escape') {
      if (document.querySelector('.cc-menu,.cc-emo,.cc-card')) closeMenus();
      else if (S.aside) { S.aside = null; cc.classList.remove('aside-on'); renderHead(); }
    }
  });
  // Pegar y arrastrar archivos
  document.addEventListener('paste', e => {
    if (!$('cc-ta') || !e.clipboardData) return;
    const files = [...e.clipboardData.files];
    if (files.length && (e.target.id === 'cc-ta' || e.target === document.body)) { e.preventDefault(); addFiles(files.map(f => f.name === 'image.png' ? new File([f], `captura-${Date.now()}.png`, { type: f.type }) : f)); }
  });
  let dragN = 0;
  main.addEventListener('dragenter', e => { if ([...e.dataTransfer.types].includes('Files') && $('cc-box')) { dragN++; $('cc-box').classList.add('drag'); } });
  main.addEventListener('dragleave', () => { if (--dragN <= 0) { dragN = 0; $('cc-box')?.classList.remove('drag'); } });
  main.addEventListener('dragover', e => { if ($('cc-box')) e.preventDefault(); });
  main.addEventListener('drop', e => { if (!$('cc-box')) return; e.preventDefault(); dragN = 0; $('cc-box').classList.remove('drag'); addFiles(e.dataTransfer.files); });
  // En Archivos, Alt+clic lleva al mensaje donde se compartió
  list.addEventListener('click', e => { const f = e.target.closest('[data-filemsg]'); if (f && e.altKey) { e.preventDefault(); gotoMessage(+f.dataset.filemsg); } });

  // Plantilla de bienvenida (para volver a ella al cerrar una conversación)
  const tpl = document.createElement('template'); tpl.id = 'cc-welcome-tpl'; tpl.innerHTML = main.innerHTML; document.body.appendChild(tpl);
  boot();
  // Tarjetitas de vínculos: se completan solas cuando aparecen mensajes nuevos (en la conversación, el hilo o la búsqueda)
  if (window.CCRefs) { let t = null; new MutationObserver(() => { clearTimeout(t); t = setTimeout(() => CCRefs.hydrate(document.body, api), 120); }).observe(document.body, { childList: true, subtree: true }); }
})();
