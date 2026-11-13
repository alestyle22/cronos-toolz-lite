/* chat/refs.js — Vínculos a clientes, contenidos, tareas y tickets en el chat (burbuja y chat grande), y selector de emojis
   reutilizable. En el texto del mensaje un vínculo es [[tipo:id]]; acá se dibuja como tarjetita y se completa con
   ref_resolve (el servidor solo da el título si quien lee lo puede ver: si no, "Sin acceso"). Doc: toolz/CHAT_VINCULOS.md */
(() => {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ICON = { cliente: 'fa-address-book', contenido: 'fa-layer-group', tarea: 'fa-list-check', ticket: 'fa-ticket', jira: 'fa-jira' };
  const COLOR = { cliente: '#3b82f6', contenido: '#a78bfa', tarea: '#22c55e', ticket: '#f97316', jira: '#2684ff' };
  const RE = /\[\[(?:(cliente|contenido|tarea|ticket):(\d{1,9})|(jira):([A-Z][A-Z0-9_]{0,19}-\d{1,9}))\]\]/g;
  // Los de Jira abren en otra pestaña (están en Jira, no en Cronos)
  const ext = k => k === 'jira' ? ' target="_blank" rel="noopener"' : '';
  const cache = {};
  let kindsP = null, pk = null;

  /** Reemplaza los [[tipo:id]] de un texto YA ESCAPADO por la tarjetita (se completa con hydrate). */
  // Si el dato ya está en la caché se dibuja terminado: los chats redibujan los mensajes cada pocos segundos y,
  // si cada vez volviera a "Cargando…", las tarjetitas parpadean.
  function chip(k, id) {
    const key = k + ':' + id;
    if (!(key in cache)) return `<a class="cc-ref" data-ref="${key}" href="#" style="--rc:${COLOR[k]}"><i class="fa-solid ${ICON[k]}"></i><span>Cargando…</span></a>`;
    const r = cache[key];
    if (!r || !r.ok) return `<a class="cc-ref na" data-ref="${key}" data-ok="1" style="--rc:${COLOR[k]}" title="No tienes acceso a este elemento o ya no existe"><i class="fa-solid fa-lock"></i><span>${esc((r && r.label) || 'Elemento')} sin acceso</span></a>`;
    return `<a class="cc-ref" data-ref="${key}" data-ok="1" href="${esc(r.href)}"${ext(k)} style="--rc:${COLOR[k]}" title="${esc(r.label + ': ' + r.title + (r.sub ? ' · ' + r.sub : ''))}"><i class="fa-solid ${esc(r.icon)}"></i>${r.code ? `<em>${esc(r.code)}</em>` : ''}<span>${esc(r.title)}</span>${r.sub ? `<small>${esc(r.sub)}</small>` : ''}</a>`;
  }
  function fmt(escaped) {
    return String(escaped).replace(RE, (_, k, id, jk, jid) => chip(k || jk, id || jid));
  }

  function paint(a, r) {
    a.dataset.ok = '1';
    if (!r || !r.ok) {
      a.classList.add('na'); a.removeAttribute('href'); a.title = 'No tienes acceso a este elemento o ya no existe';
      a.innerHTML = `<i class="fa-solid fa-lock"></i><span>${esc((r && r.label) || 'Elemento')} sin acceso</span>`;
      return;
    }
    a.href = r.href; if (r.ext) { a.target = '_blank'; a.rel = 'noopener'; } a.title = r.label + ': ' + r.title + (r.sub ? ' · ' + r.sub : '');
    a.innerHTML = `<i class="fa-solid ${esc(r.icon)}"></i>${r.code ? `<em>${esc(r.code)}</em>` : ''}<span>${esc(r.title)}</span>${r.sub ? `<small>${esc(r.sub)}</small>` : ''}`;
  }

  /** Completa las tarjetitas que haya dentro de root (un pedido por tanda, con caché). */
  async function hydrate(root, api) {
    if (!root) return;
    const els = [...root.querySelectorAll('a.cc-ref:not([data-ok])')];
    if (!els.length) return;
    const need = [...new Set(els.map(a => a.dataset.ref).filter(r => !(r in cache)))];
    if (need.length) {
      try { const d = await api('ref_resolve', { refs: need.slice(0, 60) }); Object.assign(cache, d.refs || {}); }
      catch (e) { return; }
    }
    els.forEach(a => { if (a.isConnected && a.dataset.ref in cache) paint(a, cache[a.dataset.ref]); });
  }

  function close() { if (pk) { pk.remove(); pk = null; document.removeEventListener('mousedown', outside, true); document.removeEventListener('keydown', onKey, true); } }
  function outside(e) { if (pk && !pk.contains(e.target) && !e.target.closest('[data-refpk-open]')) close(); }
  function onKey(e) { if (e.key === 'Escape' && pk) { e.stopPropagation(); close(); } }
  function place(el, anchor) {
    const r = anchor.getBoundingClientRect(), w = el.offsetWidth, h = el.offsetHeight;
    let x = Math.min(Math.max(8, r.right - w), innerWidth - w - 8), y = r.top - h - 8;
    if (y < 8) y = Math.min(innerHeight - h - 8, r.bottom + 8);
    el.style.left = x + 'px'; el.style.top = y + 'px';
  }
  function open(anchor, html) {
    close();
    pk = document.createElement('div'); pk.className = 'cc-pk'; pk.innerHTML = html;
    document.body.appendChild(pk); place(pk, anchor);
    setTimeout(() => { document.addEventListener('mousedown', outside, true); document.addEventListener('keydown', onKey, true); }, 0);
    return pk;
  }

  /** Selector "Vincular": pestañas por tipo, buscador y resultados. onPick recibe el texto [[tipo:id]]. */
  // opts.only: limitar los tipos (por ejemplo ['cliente', 'contenido'] en los tickets); opts.sub: texto de ayuda
  async function picker(anchor, api, onPick, opts = {}) {
    const only = opts.only || null, sub = opts.sub || 'Clientes, contenidos, tareas, tickets o Jira';
    if (pk && pk.dataset.kind === 'ref') return close();
    const el = open(anchor, `<div class="cc-pk-h"><b><i class="fa-solid fa-link"></i> Vincular</b><small>${esc(sub)}</small></div>
      <div class="cc-pk-tabs"></div><input class="cc-pk-q" type="search" placeholder="Buscar por nombre o número (#45, T-0003, DIS-12)" aria-label="Buscar qué vincular" autocomplete="off">
      <div class="cc-pk-list"><div class="cc-pk-empty">Cargando…</div></div>`);
    el.dataset.kind = 'ref';
    const kinds = only ? await api('ref_kinds', { only }).then(d => d.kinds || []).catch(() => [])
      : await (kindsP = kindsP || api('ref_kinds').then(d => d.kinds || []).catch(() => { kindsP = null; return []; }));
    if (!el.isConnected) return;
    if (!kinds.length) { el.querySelector('.cc-pk-list').innerHTML = '<div class="cc-pk-empty">No tienes acceso a nada que se pueda vincular acá.</div>'; el.querySelector('.cc-pk-q').hidden = true; return; }
    let kind = '', t = null, seq = 0;
    el.querySelector('.cc-pk-tabs').innerHTML = `<button type="button" class="on" data-k="">Todo</button>` + kinds.map(k => `<button type="button" data-k="${esc(k.kind)}"><i class="fa-solid ${esc(k.icon)}"></i>${esc(k.label)}${k.kind === 'jira' ? '' : 's'}</button>`).join('');
    const list = el.querySelector('.cc-pk-list'), q = el.querySelector('.cc-pk-q');
    async function run() {
      const my = ++seq;
      list.innerHTML = '<div class="cc-pk-empty"><i class="fa-solid fa-spinner fa-spin"></i></div>';
      let items = [];
      try { items = (await api('ref_search', only ? { q: q.value, kind, only } : { q: q.value, kind })).items || []; } catch (e) { if (my === seq) list.innerHTML = `<div class="cc-pk-empty">${esc(e.message)}</div>`; return; }
      if (my !== seq || !el.isConnected) return;
      items.forEach(r => { cache[r.kind + ':' + r.id] = r; });
      list.innerHTML = items.length ? items.map(r => `<button type="button" class="cc-pk-it" data-ref="${esc(r.kind)}:${r.id}" style="--rc:${esc(r.color)}"><i class="fa-solid ${esc(r.icon)}"></i><span><b>${r.code ? `<em>${esc(r.code)}</em> ` : ''}${esc(r.title)}</b><small>${esc(r.label)}${r.sub ? ' · ' + esc(r.sub) : ''}</small></span></button>`).join('')
        : `<div class="cc-pk-empty">${q.value ? 'No hay resultados para "' + esc(q.value) + '".' : 'No hay nada para vincular todavía.'}</div>`;
      place(el, anchor);
    }
    el.addEventListener('click', e => {
      const b = e.target.closest('[data-k]');
      if (b) { kind = b.dataset.k; el.querySelectorAll('[data-k]').forEach(x => x.classList.toggle('on', x === b)); run(); q.focus(); return; }
      const it = e.target.closest('.cc-pk-it');
      if (it) { onPick('[[' + it.dataset.ref + ']]'); close(); }
    });
    q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 250); });
    q.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const f = list.querySelector('.cc-pk-it'); if (f) f.click(); } });
    q.focus(); run();
  }

  /** Selector de emojis (usa window.CC_EMOJI de chat/emoji.js). */
  function emoji(anchor, onPick) {
    if (pk && pk.dataset.kind === 'emoji') return close();
    const data = window.CC_EMOJI || [];
    const el = open(anchor, `<input class="cc-pk-q" type="search" placeholder="Buscar emoji (risa, gracias, ok…)" aria-label="Buscar emoji" autocomplete="off"><div class="cc-pk-em"></div>`);
    el.dataset.kind = 'emoji'; el.classList.add('em');
    const box = el.querySelector('.cc-pk-em'), q = el.querySelector('.cc-pk-q');
    const draw = () => {
      const s = q.value.trim().toLowerCase();
      box.innerHTML = data.map(([cat, list]) => { const l = s ? list.filter(([e, w]) => w.includes(s)) : list; return l.length ? `<div class="cc-pk-cat">${esc(cat)}</div><div class="cc-pk-grid">${l.map(([e, w]) => `<button type="button" data-em="${esc(e)}" title="${esc(w)}">${e}</button>`).join('')}</div>` : ''; }).join('') || '<div class="cc-pk-empty">No hay emojis con esa palabra.</div>';
    };
    q.addEventListener('input', draw);
    el.addEventListener('click', e => { const b = e.target.closest('[data-em]'); if (b) { onPick(b.dataset.em); close(); } });
    draw(); place(el, anchor); q.focus();
  }

  /** Inserta texto en un textarea donde está el cursor. */
  function insert(ta, text) {
    const p = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? p;
    const before = ta.value.slice(0, p), pad = before && !/\s$/.test(before) && text.startsWith('[[') ? ' ' : '';
    const add = pad + text + (text.startsWith('[[') ? ' ' : '');
    ta.value = before + add + ta.value.slice(e);
    ta.focus(); ta.setSelectionRange(p + add.length, p + add.length);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // ── "Está trabajando en…" y "Tareas en curso" de una persona (trabajo/user_work_lib.php). w = null: sin acceso a Trabajo ──
  const ago = dt => { const m = Math.max(0, Math.round((Date.now() - ((window.G7Time && G7Time.parse(dt)) || new Date(String(dt).replace(' ', 'T')))) / 60000)); return m < 1 ? 'recién' : m < 60 ? `hace ${m} min` : `hace ${Math.floor(m / 60)} h ${m % 60 ? (m % 60) + ' min' : ''}`.trim(); };
  const due = d => { if (!d) return ''; const days = Math.round((new Date(d + 'T12:00:00') - new Date(new Date().toDateString() + ' 12:00')) / 864e5);
    return days < 0 ? `<span class="cc-wk-due late">Vencida</span>` : days === 0 ? '<span class="cc-wk-due soon">Vence hoy</span>' : days === 1 ? '<span class="cc-wk-due soon">Mañana</span>' : `<span class="cc-wk-due">${d.slice(8, 10)}/${d.slice(5, 7)}</span>`; };
  function work(w, name = 'Esta persona') {
    if (!w) return '';
    const who = name === 'Tú' ? 'Estás' : esc(name) + ' está';
    let h = '<div class="cc-wk">';
    if (w.now) h += w.now.hidden
      ? `<div class="cc-wk-now"><span class="cc-wk-live"></span><span><b>${who} trabajando en una tarea</b><small>que no puedes ver · ${esc(ago(w.now.since))}</small></span></div>`
      : `<a class="cc-wk-now" href="${esc(w.now.href)}"><span class="cc-wk-live"></span><span><b>${who} trabajando en ${w.now.ticket ? 'este ticket' : 'esta tarea'}</b><small><em>${esc(w.now.code)}</em> ${esc(w.now.title)} · ${esc(ago(w.now.since))}</small></span><i class="fa-solid fa-chevron-right"></i></a>`;
    h += `<div class="cc-wk-h"><i class="fa-solid fa-list-check"></i> Tareas en curso${w.total ? ` <span>${w.total}</span>` : ''}</div>`;
    h += w.tasks.length ? w.tasks.map(t => `<a class="cc-wk-t" href="${esc(t.href)}" style="--sc:${esc(t.color)}"><i class="fa-solid ${t.ticket ? 'fa-ticket' : 'fa-list-check'}"></i><span class="tt"><em>${esc(t.code)}</em> ${esc(t.title)}</span><span class="cc-wk-st">${esc(t.status)}</span>${due(t.due)}</a>`).join('')
      : `<div class="cc-wk-empty">${w.hidden ? 'Tiene tareas en curso que no puedes ver.' : 'No tiene tareas en curso.'}</div>`;
    const foot = [w.more ? `y ${w.more} más` : '', w.hidden && w.tasks.length ? `${w.hidden} que no puedes ver` : ''].filter(Boolean).join(' · ');
    if (foot) h += `<div class="cc-wk-foot">${foot}</div>`;
    return h + '</div>';
  }
  /** Una línea para encabezados chicos (burbuja): "Trabajando en T-0001 · Título". */
  function workLine(w) {
    if (!w || !w.now) return '';
    return w.now.hidden ? '<span class="cc-wk-line"><span class="cc-wk-live"></span>Trabajando en una tarea</span>'
      : `<a class="cc-wk-line" href="${esc(w.now.href)}" title="${esc(w.now.title)}"><span class="cc-wk-live"></span>Trabajando en ${esc(w.now.code)} · ${esc(w.now.title)}</a>`;
  }

  // Mientras carga, o si no hay acceso, la tarjetita no navega
  document.addEventListener('click', e => { const a = e.target.closest('a.cc-ref'); if (a && (!a.dataset.ok || a.classList.contains('na'))) e.preventDefault(); });

  window.CCRefs = { fmt, hydrate, picker, emoji, insert, close, work, workLine };
})();
