/* links/catalogo.js — Catálogo Cronos en la Biblioteca: ver las herramientas del sitio base, probarlas y agregarlas o actualizarlas.
   Habla con links/catalogo.php. Sin código en línea (CSP estricta): todo por addEventListener. Doc: toolz/CATALOGO.md */
(function () {
  'use strict';
  const btn = document.getElementById('cat-open'); if (!btn) return;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ICON = { calculadora: 'fa-calculator', generador: 'fa-wand-magic-sparkles', checklist: 'fa-list-check', plantilla: 'fa-table-cells-large', conversor: 'fa-right-left' };
  const C = { d: null, q: '', type: '', tag: '', open: null, busy: false };
  const ov = document.createElement('div');
  ov.className = 'cat-ov hidden'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'Catálogo Cronos');
  ov.innerHTML = '<div class="cat-box"><div class="cat-top"><div><div class="cat-h"><i class="fa-solid fa-toolbox"></i> Catálogo Cronos</div>'
    + '<div class="cat-sub">Herramientas listas para usar. Probalas y sumalas a tu Biblioteca: quedan guardadas en tu Toolz.</div></div>'
    + '<button class="cat-x" type="button" data-cat="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div><div class="cat-body" id="cat-body"></div></div>';
  document.body.appendChild(ov);
  const body = ov.querySelector('#cat-body');

  async function api(payload) {
    let r, d;
    try { r = await fetch('catalogo.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }); }
    catch (e) { throw new Error('Sin conexión con Toolz.'); }
    d = await r.json().catch(() => ({ error: 'Respuesta inválida (' + r.status + ').' }));
    if (d.error) throw new Error(d.error);
    return d;
  }
  function toast(m, bad) { if (typeof window.showToast === 'function') return window.showToast(m, bad ? 'error' : 'success'); let t = document.getElementById('cat-toast'); if (!t) { t = document.createElement('div'); t.id = 'cat-toast'; t.className = 'cat-toast'; document.body.appendChild(t); } t.textContent = m; t.classList.toggle('bad', !!bad); t.classList.add('on'); clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('on'), 4200); }
  const cover = (t, big) => t.cover ? `<img src="catalogo.php?cover=${encodeURIComponent(t.id)}&f=${encodeURIComponent(t.cover)}" alt="" loading="lazy">`
    : `<span class="cat-ph${big ? ' big' : ''}"><i class="fa-solid ${ICON[t.type] || 'fa-toolbox'}"></i></span>`;
  const status = t => t.locked ? `<span class="cat-st lock"><i class="fa-solid fa-lock"></i> Plan ${esc(t.plans.join(' o '))}</span>`
    : t.outdated ? '<span class="cat-st upd"><i class="fa-solid fa-arrow-up"></i> Versión nueva</span>'
    : t.have ? '<span class="cat-st have"><i class="fa-solid fa-check"></i> Ya la tenés</span>' : '';

  async function load() {
    body.innerHTML = '<div class="cat-msg"><i class="fa-solid fa-spinner fa-spin"></i> Buscando el catálogo…</div>';
    try { C.d = await api({ action: 'list' }); draw(); markCards(); }
    catch (e) { body.innerHTML = `<div class="cat-msg bad">${esc(e.message)} <button class="lb-btn" type="button" data-cat="retry">Reintentar</button></div>`; }
  }
  function filtered() {
    const q = C.q.trim().toLowerCase();
    return C.d.tools.filter(t => (!C.type || t.type === C.type) && (!C.tag || t.tags.includes(C.tag))
      && (!q || (t.name + ' ' + t.short + ' ' + t.tags.join(' ') + ' ' + t.type_name).toLowerCase().includes(q)));
  }
  function draw() {
    if (!C.d) return;
    if (C.open) return drawFicha();
    const tools = C.d.tools, types = Object.entries(C.d.types).filter(([k]) => tools.some(t => t.type === k));
    // Con muchas herramientas, solo las etiquetas más usadas (la elegida siempre se ve)
    const cnt = {}; tools.forEach(t => t.tags.forEach(g => cnt[g] = (cnt[g] || 0) + 1));
    const tags = Object.keys(cnt).sort((a, b) => cnt[b] - cnt[a] || a.localeCompare(b)).slice(0, 18);
    if (C.tag && !tags.includes(C.tag)) tags.push(C.tag);
    tags.sort();
    const list = filtered();
    const chip = (kind, val, label, on) => `<button type="button" class="cat-chip${on ? ' on' : ''}" data-cat="${kind}" data-v="${esc(val)}">${esc(label)}</button>`;
    body.innerHTML = `<div class="cat-bar"><label class="cat-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="cat-q" placeholder="Buscar herramienta" value="${esc(C.q)}" autocomplete="off"></label>
      <div class="cat-chips">${chip('type', '', 'Todas', !C.type)}${types.map(([k, v]) => chip('type', k, v, C.type === k)).join('')}</div></div>
      ${tags.length ? `<div class="cat-chips tags">${tags.map(g => chip('tag', g, '#' + g, C.tag === g)).join('')}</div>` : ''}
      <div class="cat-count">${list.length} ${list.length === 1 ? 'herramienta' : 'herramientas'}${C.q || C.type || C.tag ? ' <button type="button" class="cat-link" data-cat="clear">Borrar filtros</button>' : ''}</div>
      ${list.length ? `<div class="cat-grid">${list.map(t => `<button type="button" class="cat-card${t.locked ? ' locked' : ''}" data-cat="open" data-v="${esc(t.id)}">
        <span class="cat-cov">${cover(t)}<span class="cat-type"><i class="fa-solid ${ICON[t.type] || 'fa-toolbox'}"></i> ${esc(t.type_name)}</span></span>
        <span class="cat-cb"><b>${esc(t.name)}</b><span class="cat-sh">${esc(t.short)}</span><span class="cat-foot">${status(t)}<span class="cat-tags">${t.tags.slice(0, 3).map(g => '#' + esc(g)).join(' ')}</span></span></span></button>`).join('')}</div>`
        : `<div class="cat-msg">${tools.length ? 'No hay herramientas con esos filtros.' : 'Todavía no hay herramientas en el catálogo.'}</div>`}`;
  }
  function drawFicha() {
    const t = C.d.tools.find(x => x.id === C.open); if (!t) { C.open = null; return draw(); }
    const cats = C.d.categories || [];
    const pref = cats.find(c => /herram|util|tool/i.test(c.name)) || cats[0];
    const kb = n => n < 1024 ? n + ' B' : (n / 1024).toLocaleString('es-AR', { maximumFractionDigits: 0 }) + ' KB';
    let act;
    if (t.locked) act = `<div class="cat-note"><i class="fa-solid fa-lock"></i> Viene con el plan ${esc(t.plans.join(' o '))}. Pedila desde <a href="/toolz/soporte/">Soporte</a>.</div>`;
    else if (t.have) act = `<a class="lb-btn" href="/toolz/links/ver.php?id=${t.have}" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> Abrir la mía</a>`
      + (t.outdated ? ` <button class="lb-btn primary" type="button" data-cat="update" data-v="${t.have}"><i class="fa-solid fa-arrow-up"></i> Actualizar a la versión ${t.ver}</button><div class="cat-note">Tenés la versión ${t.have_ver}. Al actualizar se cambia solo la herramienta: el nombre, la categoría y las notas quedan como están.</div>` : '<div class="cat-note"><i class="fa-solid fa-check"></i> Ya está en tu Biblioteca, en su última versión.</div>');
    else if (!cats.length) act = '<div class="cat-note">Primero creá una categoría en la Biblioteca.</div>';
    else act = `<label class="cat-lbl">Agregarla en la categoría</label><div class="cat-add"><select id="cat-catsel" class="lb-select">${cats.map(c => `<option value="${c.id}"${pref && c.id == pref.id ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}</select>
      <button class="lb-btn primary" type="button" data-cat="add" data-v="${esc(t.id)}"><i class="fa-solid fa-plus"></i> Agregar a la Biblioteca</button></div>`;
    body.innerHTML = `<button type="button" class="cat-link back" data-cat="back"><i class="fa-solid fa-arrow-left"></i> Volver al catálogo</button>
      <div class="cat-ficha"><div class="cat-fcov">${cover(t, true)}</div><div class="cat-finfo">
        <div class="cat-type in"><i class="fa-solid ${ICON[t.type] || 'fa-toolbox'}"></i> ${esc(t.type_name)}</div><h3>${esc(t.name)}</h3><p class="cat-lead">${esc(t.short)}</p>
        ${t.tags.length ? `<div class="cat-tags">${t.tags.map(g => '#' + esc(g)).join(' ')}</div>` : ''}
        <div class="cat-meta">Versión ${t.ver} · ${esc(String(t.updated_at || '').slice(0, 10).split('-').reverse().join('/'))} · ${kb(t.size)}</div>
        <div class="cat-acts">${act}</div></div></div>
      ${t.long ? `<div class="cat-long">${esc(t.long)}</div>` : ''}
      ${t.locked ? '' : `<div class="cat-try"><div class="cat-lbl">Probala acá <span>(corre aislada: no ve tu sesión ni tus datos)</span></div>
        <div class="cat-frame" id="cat-frame"><button type="button" class="lb-btn" data-cat="try" data-v="${esc(t.id)}"><i class="fa-solid fa-play"></i> Cargar la vista previa</button></div></div>`}`;
    body.scrollTop = 0;
  }
  // Marca en las tarjetas de la Biblioteca las que tienen versión nueva
  function markCards() {
    if (!C.d) return;
    C.d.tools.filter(t => t.outdated).forEach(t => {
      const w = document.querySelector(`.link-card-wrap[data-link-id="${t.have}"]`); if (!w || w.querySelector('.cat-upd-badge')) return;
      const b = document.createElement('button'); b.type = 'button'; b.className = 'cat-upd-badge'; b.title = 'Hay una versión nueva en el Catálogo Cronos';
      b.innerHTML = '<i class="fa-solid fa-arrow-up"></i> Actualizar'; b.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); C.open = t.id; openCat(false); });
      w.appendChild(b);
    });
  }
  function openCat(reload) { ov.classList.remove('hidden'); document.body.style.overflow = 'hidden'; if (!C.d || reload) load(); else draw(); }
  function closeCat() { ov.classList.add('hidden'); document.body.style.overflow = ''; C.open = null; }
  async function act(kind, v, el) {
    if (C.busy) return;
    if (kind === 'add' || kind === 'update') {
      C.busy = true; const old = el.innerHTML; el.disabled = true; el.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (kind === 'add' ? 'Agregando…' : 'Actualizando…');
      try {
        if (kind === 'add') { const r = await api({ action: 'add', id: v, category_id: +document.getElementById('cat-catsel').value }); toast('Listo: la herramienta ya está en tu Biblioteca'); location.hash = 'link-' + r.link_id; }
        else { const r = await api({ action: 'update', link_id: +v }); toast(r.changed ? 'Actualizada a la versión ' + r.ver : 'Ya estaba en la última versión'); }
        setTimeout(() => location.reload(), 700);
      } catch (e) { toast(e.message, true); el.disabled = false; el.innerHTML = old; }
      C.busy = false; return;
    }
  }
  ov.addEventListener('click', e => {
    if (e.target === ov) return closeCat();
    const el = e.target.closest('[data-cat]'); if (!el) return;
    const k = el.dataset.cat, v = el.dataset.v ?? '';
    if (k === 'close') closeCat();
    else if (k === 'retry') load();
    else if (k === 'type') { C.type = v; draw(); }
    else if (k === 'tag') { C.tag = C.tag === v ? '' : v; draw(); }
    else if (k === 'clear') { C.q = ''; C.type = ''; C.tag = ''; draw(); }
    else if (k === 'open') { C.open = v; draw(); }
    else if (k === 'back') { C.open = null; draw(); }
    else if (k === 'try') { const f = document.getElementById('cat-frame'); f.innerHTML = `<iframe src="catalogo.php?preview=${encodeURIComponent(v)}" title="Vista previa" sandbox="allow-scripts allow-forms allow-modals allow-downloads allow-popups"></iframe>`; f.classList.add('on'); }
    else act(k, v, el);
  });
  ov.addEventListener('input', e => { if (e.target.id === 'cat-q') { C.q = e.target.value; const pos = e.target.selectionStart; draw(); const i = document.getElementById('cat-q'); i.focus(); i.setSelectionRange(pos, pos); } });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !ov.classList.contains('hidden')) { if (C.open) { C.open = null; draw(); } else closeCat(); } });
  btn.addEventListener('click', () => openCat(false));
  // Al entrar, se pide la lista en segundo plano para marcar las tarjetas con versión nueva (sin abrir nada)
  setTimeout(() => { api({ action: 'list' }).then(d => { C.d = d; markCards(); }).catch(() => {}); }, 1200);
})();
