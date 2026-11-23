/* social/perfiles/app.js — Profile Studio: projects (home) and editor. Draws with render.js (PFR); saves through ../api.php (pf_*).
 * No inline code (strict CSP): events by delegation on data-a / data-k. Doc: toolz/SOCIAL_STUDIO.md (Profile Studio) */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const R = window.PFR;
  const ST = { view: 'home', items: [], filter: 'all', q: '', tcat: '', etcat: '', ai: null, aiStyle: '', aiCat: '', aiJobs: [], id: 0, name: '', S: null, imgs: {}, tab: 'foto', past: [], future: [], cur: '', dirty: false, saving: false, savedAt: null, timer: 0, hTimer: 0, before: false, drag: null };

  // ── Helpers ─────────────────────────────────────────
  function toast(msg, bad) { const t = $('#pf-toast'); t.textContent = msg; t.className = 'pf-toast on' + (bad ? ' bad' : ''); clearTimeout(toast.t); toast.t = setTimeout(() => { t.className = 'pf-toast'; }, bad ? 6000 : 2600); }
  async function api(action, data = {}, quiet = false) {
    const r = await fetch('../api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-SS': '1' }, body: JSON.stringify({ action, ...data }) });
    const j = await r.json().catch(() => ({ error: 'La respuesta del servidor no se pudo leer.' }));
    if (!r.ok || j.error) { if (!quiet) toast(j.error || 'Algo salió mal.', true); throw new Error(j.error || 'error'); }
    return j;
  }
  async function upload(blob, kind, name) {
    const fd = new FormData(); fd.append('action', 'pf_upload'); fd.append('kind', kind); fd.append('file', blob, name);
    const r = await fetch('../api.php', { method: 'POST', headers: { 'X-SS': '1' }, body: fd });
    const j = await r.json().catch(() => ({ error: 'No se pudo subir la imagen.' }));
    if (!r.ok || j.error) { toast(j.error || 'No se pudo subir la imagen.', true); throw new Error(j.error || 'upload'); }
    return j.file;
  }
  const loadImg = url => new Promise((ok, bad) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => bad(new Error('No se pudo cargar la imagen.')); i.src = url; });
  const fileUrl = t => '/toolz/social_file.php?t=' + t;
  const toBlob = (c, type, q) => new Promise(ok => c.toBlob(ok, type, q));
  const fmtWhen = s => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T')), m = (Date.now() - d) / 60000; return m < 1 ? 'recién' : m < 60 ? 'hace ' + Math.floor(m) + ' min' : m < 1440 ? 'hace ' + Math.floor(m / 60) + ' h' : d.toLocaleDateString('es-AR', { day: 'numeric', month: 'short' }); };
  function modal(html, cls = '') { const m = $('#pf-modal'); $('#pf-dialog').className = 'pf-dialog ' + cls; $('#pf-dialog').innerHTML = html; m.hidden = false; }
  function closeModal() { $('#pf-modal').hidden = true; $('#pf-dialog').innerHTML = ''; }
  const presetOf = S => R.PRESETS[S.preset] || R.PRESETS.instagram;

  // ── Templates: thumbnails with a neutral silhouette (cached) ──
  const SIL_PHOTO = { token: 'sil', w: 600, h: 800, cutToken: 'sil', x: 0, y: .06, scale: .92, rot: 0, flip: false };
  const TPL_IMG = new Map();
  function tplThumb(id) {
    if (TPL_IMG.has(id)) return TPL_IMG.get(id);
    const c = document.createElement('canvas'); c.width = c.height = 180;
    const S = R.applyTemplate(Object.assign(R.defaults(), { photo: SIL_PHOTO }), id);
    try { R.draw(c, S, { photo: R.silhouette(), cut: R.silhouette() }, { clip: true }); } catch (e) {}
    const u = c.toDataURL('image/png'); TPL_IMG.set(id, u); return u;
  }
  function tplGrid(cat, act) {
    const list = R.TEMPLATES.filter(t => !cat || t.cat === cat);
    return `<div class="pf-chips pf-tcats">${[['', 'Todas'], ...Object.entries(R.TPL_CATS)].map(([k, n]) => `<button type="button" class="pf-chip${cat === k ? ' on' : ''}" data-a="${act}-cat" data-v="${k}">${esc(n)}</button>`).join('')}</div>
      <div class="pf-tpls">${list.map(t => `<button type="button" class="pf-tpl" data-a="${act}" data-v="${t.id}" title="${esc(t.name)}"><img src="${tplThumb(t.id)}" alt=""><span>${esc(t.name)}</span></button>`).join('')}</div>`;
  }

  // ── AI (Leonardo) ───────────────────────────────────
  const CAT_ICON = { real: 'fa-camera', ilustrado: 'fa-palette', gaming: 'fa-gamepad', pro: 'fa-briefcase' };
  async function aiMeta() { if (!ST.ai) { try { ST.ai = await api('pf_ai_meta', {}, true); } catch (e) { ST.ai = { ready: false, styles: [], cats: {} }; } } return ST.ai; }
  function styleCards(sel, act, cat) {
    const A = ST.ai; const list = A.styles.filter(x => !cat || x.cat === cat);
    return `<div class="pf-chips pf-tcats">${[['', 'Todos'], ...Object.entries(A.cats)].map(([k, n]) => `<button type="button" class="pf-chip${cat === k ? ' on' : ''}" data-a="${act}-cat" data-v="${k}">${esc(n)}</button>`).join('')}</div>
      <div class="pf-styles">${list.map(x => `<button type="button" class="pf-style c-${x.cat}${sel === x.id ? ' on' : ''}" data-a="${act}" data-v="${x.id}">${x.thumb ? `<img src="${esc(x.thumb)}" alt="" loading="lazy">` : `<span class="ph"><i class="fa-solid ${CAT_ICON[x.cat] || 'fa-star'}"></i></span>`}<span>${esc(x.name)}</span></button>`).join('')}</div>`;
  }
  /** Asks for the status every 3 s until it finishes (max. 5 minutes). */
  async function waitJob(id) {
    for (let i = 0; i < 100; i++) {
      await new Promise(r => setTimeout(r, i ? 3000 : 2500));
      let r; try { r = await api('pf_ai_status', { id }, true); } catch (e) { if (i > 3) throw e; continue; }
      if (r.status === 'COMPLETE') return r.images;
      if (r.status === 'FAILED') throw new Error(r.error || 'Leonardo no pudo generar la imagen.');
    }
    throw new Error('Leonardo está tardando demasiado: probá de nuevo en un rato.');
  }
  const OP_LABEL = { generate: 'Avatar con IA', transform: 'Estilo', edit: 'Cambio', upscale: 'Mejorada', nobg: 'Sin fondo (IA)' };
  /** Starts a request from the editor and adds its images to the project's results when they arrive. */
  async function aiRun(op, extra) {
    const S = ST.S, label = extra.label || OP_LABEL[op];
    let r; try { r = await api('pf_ai_start', { op, project_id: ST.id, photo_token: S.photo?.token, ...extra }); } catch (e) { return; }
    const job = { id: r.id, op, label }; ST.aiJobs.push(job); drawLeft();
    toast('Pedido enviado a Leonardo' + (r.cost ? ` (${r.cost} créditos)` : '') + '. Tarda entre 10 y 60 segundos.');
    try {
      const imgs = await waitJob(r.id);
      S.ai = [...imgs.map(im => ({ token: im.token, w: im.w, h: im.h, op, label })), ...(S.ai || [])].slice(0, 16);
      changed(); toast(imgs.length > 1 ? `Listas ${imgs.length} imágenes` : 'Lista la imagen');
    } catch (e) { toast(e.message, true); }
    finally { ST.aiJobs = ST.aiJobs.filter(j => j !== job); drawLeft(); }
  }
  const AI_CHIPS = [['Ropa', ['traje formal', 'ropa casual', 'buzo gamer con auriculares', 'camiseta deportiva', 'campera de cuero']],
    ['Expresión', ['sonrisa natural', 'expresión seria y segura', 'mirada amable', 'sonrisa amplia']],
    ['Luz', ['luz de estudio suave', 'luz de atardecer', 'luces de neón', 'luz dramática de cine']],
    ['Escenario', ['oficina moderna', 'ciudad de noche', 'naturaleza', 'el espacio', 'estudio con fondo liso']]];
  function aiPanel() {
    const A = ST.ai, S = ST.S;
    if (!A) { aiMeta().then(() => { if (ST.tab === 'ia') drawLeft(); }); return '<div class="pf-blk"><i class="fa-solid fa-spinner fa-spin"></i></div>'; }
    if (!A.plan) return '<div class="pf-blk"><div class="pf-note"><i class="fa-solid fa-lock"></i><span>Tu plan no incluye la IA de Profile Studio. Para sumarla, mejorá el plan desde Admin › Licencia.</span></div></div>';
    if (!A.ready) return '<div class="pf-blk"><div class="pf-note"><i class="fa-solid fa-plug"></i><span>La IA usa Leonardo, que todavía no está configurado: un administrador tiene que cargar la clave en Admin › Inteligencia artificial.</span></div></div>';
    const busy = ST.aiJobs.length;
    const results = (S.ai || []);
    const M = A.month || {};
    return `${M.cap != null ? `<p class="pf-hint" style="margin:10px 0 0"><i class="fa-solid fa-gauge"></i> Este mes la agencia usó ${M.used} de ${M.cap} pedidos de IA de su plan.</p>` : ''}${busy ? `<div class="pf-note"><i class="fa-solid fa-spinner fa-spin"></i><span>Generando ${busy > 1 ? busy + ' pedidos' : ''} con Leonardo… podés seguir editando.</span></div>` : ''}
      ${results.length ? `<div class="pf-blk"><h4>Resultados</h4><div class="pf-results">${results.map((r, i) => `<figure class="pf-res"><img src="${fileUrl(r.token)}" alt="" loading="lazy"><figcaption>${esc(r.label || '')}</figcaption>
        <div class="acts"><button type="button" class="pf-btn" data-a="ai-use" data-i="${i}">${r.op === 'nobg' ? 'Usar recorte' : 'Usar'}</button><a class="pf-ico" href="${fileUrl(r.token)}&amp;dl=1" title="Bajar" aria-label="Bajar"><i class="fa-solid fa-download"></i></a><button type="button" class="pf-ico" data-a="ai-drop" data-i="${i}" title="Quitar de la lista" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div></figure>`).join('')}</div></div>` : ''}
      ${S.photo ? `<div class="pf-blk"><h4>Transformar mi foto</h4><p class="pf-hint" style="margin:0 0 8px">Leonardo la pasa al estilo que elijas manteniendo tu cara.</p>${styleCards(ST.aiStyle, 'ai-style', ST.aiCat)}
          <label class="pf-f" style="margin-top:8px"><span>Detalles (opcional)</span><input class="pf-in" id="ai-tr-extra" maxlength="300" placeholder="Ej.: con anteojos, fondo azul"></label>
          <div class="pf-row"><label class="pf-f"><span>Versiones</span><select class="pf-in" id="ai-tr-qty"><option value="1">1</option><option value="2">2</option></select></label><button type="button" class="pf-btn" data-a="ai-transform"${ST.aiStyle ? '' : ' disabled'}><i class="fa-solid fa-wand-magic-sparkles"></i> Transformar</button></div></div>
        <div class="pf-blk"><h4>Cambiar detalles</h4>${AI_CHIPS.map(([g, list]) => `<div class="pf-sub">${g}</div><div class="pf-chips">${list.map(x => `<button type="button" class="pf-chip" data-a="ai-chip" data-v="${esc(x)}">${esc(x)}</button>`).join('')}</div>`).join('')}
          <label class="pf-f" style="margin-top:8px"><span>Qué cambiar</span><textarea class="pf-in" id="ai-ed-text" rows="2" maxlength="600" placeholder="Ej.: con traje formal y luz de estudio suave"></textarea></label>
          <div class="pf-row">${A.suggest ? '<button type="button" class="pf-btn pf-btn-ghost" data-a="ai-suggest" data-v="edit"><i class="fa-solid fa-lightbulb"></i> Ideas</button>' : ''}<button type="button" class="pf-btn" data-a="ai-edit"><i class="fa-solid fa-pen-ruler"></i> Aplicar cambio</button></div><div id="ai-ideas-edit"></div></div>
        <div class="pf-blk"><h4>Mejorar</h4><div class="pf-row"><button type="button" class="pf-btn pf-btn-ghost" data-a="ai-upscale"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> Mejorar calidad (x2)</button>
          <button type="button" class="pf-btn pf-btn-ghost" data-a="ai-nobg"><i class="fa-solid fa-scissors"></i> Quitar fondo con IA</button></div><p class="pf-hint">El quitado con IA gasta créditos pero suele salir mejor en el pelo que el del navegador (gratis, pestaña Foto).</p></div>`
      : '<div class="pf-blk"><p class="pf-hint">Subí una foto (pestaña Foto) para transformarla o mejorarla. Para crear un avatar desde cero, usá "Crear con IA" en la pantalla de inicio.</p></div>'}
      ${A.admin ? `<div class="pf-blk"><h4>Miniaturas de los estilos</h4><p class="pf-hint" style="margin:0 0 8px">${A.styles.filter(x => x.thumb).length} de ${A.styles.length} tienen miniatura. Las que faltan se muestran con un dibujo. Generarlas usa Leonardo y gasta créditos de la cuenta (una imagen chica por estilo, una sola vez).</p>
        <button type="button" class="pf-btn pf-btn-ghost" data-a="ai-thumbs"><i class="fa-solid fa-images"></i> Generar las que faltan</button></div>` : ''}`;
  }
  function ideasHtml(list, target) {
    return list.length ? `<ul class="pf-ideas">${list.map(x => `<li><button type="button" class="pf-idea" data-a="ai-idea" data-t="${target}" data-v="${esc(x)}">${esc(x)}</button></li>`).join('')}</ul>` : '';
  }
  async function aiHome() {
    await aiMeta(); const A = ST.ai;
    if (!A.plan || !A.ready) return toast(!A.plan ? 'Tu plan no incluye la IA de Profile Studio.' : 'Falta configurar Leonardo en Admin › Inteligencia artificial.', true);
    ST.homeStyle = ''; ST.homeCat = '';
    modal(`<div class="pf-dg-h"><h2><i class="fa-solid fa-wand-magic-sparkles"></i> Crear un avatar con IA</h2><button type="button" class="pf-ico" data-a="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="pf-dg-b"><label class="pf-f"><span>Describí el avatar</span><textarea class="pf-in" id="ai-gen-text" rows="3" maxlength="800" placeholder="Ej.: una chica con pelo corto violeta y campera de cuero, mirada segura"></textarea></label>
        <div class="pf-row">${A.suggest ? '<button type="button" class="pf-btn pf-btn-ghost" data-a="ai-suggest" data-v="gen"><i class="fa-solid fa-lightbulb"></i> Ideas</button>' : ''}</div><div id="ai-ideas-gen"></div>
        <div class="pf-sub">Estilo (opcional)</div><div id="ai-gen-styles">${styleCards('', 'home-style', '')}</div>
        <label class="pf-f" style="margin-top:10px;max-width:160px"><span>Cuántas opciones</span><select class="pf-in" id="ai-gen-qty"><option>1</option><option selected>2</option><option>3</option><option>4</option></select></label>
        <div id="ai-gen-res"></div></div>
      <div class="pf-dg-f"><span class="pf-hint" style="margin:0">Leonardo tarda entre 10 y 60 segundos y gasta créditos de la cuenta.</span><span class="sp"></span><button type="button" class="pf-btn" data-a="ai-generate"><i class="fa-solid fa-wand-magic-sparkles"></i> Generar</button></div>`, 'wide');
  }

  // ── Home: projects ──────────────────────────────────
  async function home() {
    ST.view = 'home'; ST.id = 0; ST.S = null; document.body.classList.remove('pf-editing');
    try { ST.items = (await api('pf_list')).items; } catch (e) { ST.items = []; }
    try { ST.gal = await api('pf_gallery', {}, true); } catch (e) { ST.gal = { on: false, items: [], can_config: false }; }
    drawHome();
  }
  function drawHome() {
    const list = ST.items.filter(p => (ST.filter !== 'fav' || p.favorite) && (!ST.q || p.name.toLowerCase().includes(ST.q.toLowerCase())));
    const card = p => `<article class="pf-card" data-a="open" data-id="${p.id}" tabindex="0">
        <div class="pf-thumb">${p.thumb ? `<img src="${esc(p.thumb)}" alt="" loading="lazy">` : '<i class="fa-regular fa-user"></i>'}</div>
        <div class="pf-card-b"><b>${esc(p.name)}</b><small>${p.preset && R.PRESETS[p.preset] ? `<i class="${R.PRESETS[p.preset][1]}"></i> ${esc(R.PRESETS[p.preset][0])} · ` : ''}${esc(fmtWhen(p.updated_at))}</small></div>
        <div class="pf-card-acts"><button type="button" class="pf-ico${p.favorite ? ' on' : ''}" data-a="fav" data-id="${p.id}" title="${p.favorite ? 'Sacar de favoritos' : 'Favorito'}" aria-label="Favorito"><i class="fa-${p.favorite ? 'solid' : 'regular'} fa-star"></i></button>
          ${ST.gal?.on ? `<button type="button" class="pf-ico${p.shared ? ' on' : ''}" data-a="share" data-id="${p.id}" title="${p.shared ? 'Sacar de la galería de la agencia' : 'Compartir en la galería de la agencia (solo la miniatura y el diseño, nunca la foto)'}" aria-label="Compartir"><i class="fa-solid fa-users"></i></button>` : ''}
          <button type="button" class="pf-ico" data-a="dup" data-id="${p.id}" title="Duplicar" aria-label="Duplicar"><i class="fa-regular fa-copy"></i></button>
          <button type="button" class="pf-ico" data-a="ren" data-id="${p.id}" title="Cambiar nombre" aria-label="Cambiar nombre"><i class="fa-solid fa-pen"></i></button>
          <button type="button" class="pf-ico" data-a="del" data-id="${p.id}" title="Borrar" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button></div></article>`;
    $('#pf').innerHTML = `<header class="pf-head"><div><a class="pf-back" href="/toolz/social/"><i class="fa-solid fa-arrow-left"></i> Social Studio</a>
        <h1><i class="fa-solid fa-circle-user"></i> Profile Studio</h1><p>Armá la foto de perfil de cada red: recortala, mejorala, cambiale el fondo, sumale un marco y bajala en la medida justa.</p></div></header>
      <section class="pf-start">
        <button type="button" class="pf-big" data-a="new-photo"><i class="fa-solid fa-image"></i><b>Subir una foto</b><small>JPG, PNG o WebP</small></button>
        <button type="button" class="pf-big" data-a="ai-home"><i class="fa-solid fa-wand-magic-sparkles"></i><b>Crear con IA</b><small>Un avatar desde una descripción</small></button>
        <button type="button" class="pf-big" data-a="new-blank"><i class="fa-regular fa-square-plus"></i><b>Empezar en blanco</b><small>Fondo y marco; la foto después</small></button>
      </section>
      <section class="pf-sec pf-sec-tpl"><div class="pf-sec-h"><h2>Empezar con una plantilla</h2></div>${tplGrid(ST.tcat, 'home-tpl')}</section>
      <section class="pf-sec"><div class="pf-sec-h"><h2>Mis proyectos</h2>
        <div class="pf-tools"><div class="pf-seg"><button type="button" data-a="filter" data-f="all" class="${ST.filter === 'all' ? 'on' : ''}">Todos</button><button type="button" data-a="filter" data-f="fav" class="${ST.filter === 'fav' ? 'on' : ''}"><i class="fa-solid fa-star"></i> Favoritos</button></div>
        <input class="pf-in" id="pf-q" type="search" placeholder="Buscar" value="${esc(ST.q)}" aria-label="Buscar proyectos"></div></div>
        ${list.length ? `<div class="pf-grid">${list.map(card).join('')}</div>` : `<div class="pf-empty"><i class="fa-regular fa-images"></i><p>${ST.items.length ? 'Nada con ese filtro.' : 'Todavía no hay proyectos. Subí una foto para empezar.'}</p></div>`}
        <p class="pf-hint"><i class="fa-solid fa-lock"></i> Tus proyectos son privados: solo los ves vos${ST.gal?.on ? ', salvo los que compartas en la galería (solo su miniatura y su diseño)' : ''}.</p></section>
      ${ST.gal?.on ? `<section class="pf-sec" style="margin-top:28px"><div class="pf-sec-h"><h2>Galería de la agencia</h2></div>
        ${ST.gal.items.length ? `<div class="pf-grid">${ST.gal.items.map(g => `<article class="pf-card" data-a="gal" data-id="${g.id}" tabindex="0"><div class="pf-thumb"><img src="${esc(g.thumb)}" alt="" loading="lazy"></div>
          <div class="pf-card-b"><b>${esc(g.name)}</b><small>${esc(g.who)}${g.mine ? ' (vos)' : ''}</small></div></article>`).join('')}</div>` : '<div class="pf-empty"><i class="fa-solid fa-users"></i><p>Todavía nadie compartió un diseño. Se comparte con el ícono <i class="fa-solid fa-users"></i> de cada proyecto.</p></div>'}</section>` : ''}
      ${ST.gal?.can_config ? `<p class="pf-hint" style="margin-top:20px"><label class="pf-tg" style="display:inline-flex"><input type="checkbox" data-a-input="gallery"${ST.gal.on ? ' checked' : ''}><span></span>Galería de la agencia: que cada persona pueda compartir sus diseños (solo la miniatura y el diseño, nunca la foto)</label></p>` : ''}`;
    $('#pf-q').addEventListener('input', e => { ST.q = e.target.value; const pos = e.target.selectionStart; drawHome(); const q = $('#pf-q'); q.focus(); q.setSelectionRange(pos, pos); });
  }

  // ── Open / create ───────────────────────────────────
  async function loadImgs() {
    const S = ST.S, I = {};
    if (S.photo?.token) I.photo = await loadImg(fileUrl(S.photo.token)).catch(() => null);
    if (S.photo?.cutToken) I.cut = await loadImg(fileUrl(S.photo.cutToken)).catch(() => null);
    if (S.bg?.token) I.bg = await loadImg(fileUrl(S.bg.token)).catch(() => null);
    ST.imgs = I;
  }
  async function open(id) {
    try {
      const p = (await api('pf_get', { id })).project;
      ST.id = p.id; ST.name = p.name; ST.S = Object.assign(R.defaults(), p.state || {});
      ['adj', 'bg', 'frame', 'fx', 'badge', 'subject'].forEach(k => { ST.S[k] = Object.assign(R.defaults()[k], (p.state || {})[k] || {}); });
      ST.S.fx = Object.assign(R.defaults().fx, ST.S.fx);
      await loadImgs();
      startEditor();
      if (location.hash !== '#p/' + id) history.replaceState(null, '', '#p/' + id);
    } catch (e) { location.hash = ''; home(); }
  }
  async function createFrom(file, tpl = '') {
    let f = file;
    try { if (window.g7PrepImage) f = await window.g7PrepImage(file, { max: 2048, maxBytes: 3 * 1024 * 1024 }); } catch (e) {}
    toast('Subiendo la foto…');
    const up = await upload(f, 'photo', f.name || 'foto.jpg');
    const S = R.defaults(); S.bg.type = 'photo';
    S.photo = { token: up.token, w: up.w, h: up.h, cutToken: null, x: 0, y: 0, scale: 1, rot: 0, flip: false };
    if (tpl) return create(R.applyTemplate(S, tpl), (R.TEMPLATES.find(t => t.id === tpl) || {}).name || 'Imagen de perfil');
    return create(S, (file.name || 'Foto').replace(/\.[a-z0-9]+$/i, '').slice(0, 80) || 'Imagen de perfil');
  }
  async function create(S, name) {
    const r = await api('pf_save', { id: 0, name, state: S, preset: S.preset });
    location.hash = '#p/' + r.id;   // route() opens it
  }

  // ── Editor ──────────────────────────────────────────
  function startEditor() {
    ST.view = 'editor'; ST.past = []; ST.future = []; ST.cur = JSON.stringify(ST.S); ST.dirty = false; ST.savedAt = Date.now();
    document.body.classList.add('pf-editing');
    $('#pf').innerHTML = `<div class="pf-ed">
      <div class="pf-bar">
        <button type="button" class="pf-ico" data-a="home" title="Volver a mis proyectos" aria-label="Volver"><i class="fa-solid fa-arrow-left"></i></button>
        <input class="pf-name" id="pf-name" value="${esc(ST.name)}" maxlength="120" aria-label="Nombre del proyecto">
        <span class="pf-save" id="pf-save"></span><span class="sp"></span>
        <button type="button" class="pf-ico" data-a="undo" title="Deshacer (Ctrl+Z)" aria-label="Deshacer"><i class="fa-solid fa-rotate-left"></i></button>
        <button type="button" class="pf-ico" data-a="redo" title="Rehacer (Ctrl+Y)" aria-label="Rehacer"><i class="fa-solid fa-rotate-right"></i></button>
        <button type="button" class="pf-ico" data-a="before" title="Mantené apretado para ver la foto original" aria-label="Antes y después"><i class="fa-solid fa-circle-half-stroke"></i></button>
        <button type="button" class="pf-ico" data-a="history" title="Historial de versiones" aria-label="Historial"><i class="fa-solid fa-clock-rotate-left"></i></button>
        <button type="button" class="pf-btn pf-btn-ghost" data-a="version" title="Guardar una versión (Ctrl+S)"><i class="fa-regular fa-floppy-disk"></i><span>Guardar versión</span></button>
        <button type="button" class="pf-btn" data-a="export" title="Exportar (Ctrl+E)"><i class="fa-solid fa-download"></i><span>Exportar</span></button>
      </div>
      <aside class="pf-left" id="pf-left"></aside>
      <section class="pf-stage" id="pf-stage"><div class="pf-canvas-wrap"><canvas id="pf-cv" width="720" height="720" aria-label="Vista previa de la imagen de perfil"></canvas><div class="pf-guide" id="pf-guide"></div></div>
        <div class="pf-stage-f" id="pf-stage-f"></div></section>
      <aside class="pf-right" id="pf-right"></aside></div>`;
    $('#pf-name').addEventListener('change', e => { ST.name = e.target.value.trim() || 'Imagen de perfil'; save(); });
    bindCanvas(); drawPanels(); paint();
  }
  const TABS = [['plantillas', 'fa-swatchbook', 'Plantillas'], ['ia', 'fa-wand-magic-sparkles', 'IA'], ['foto', 'fa-image', 'Foto'], ['fondo', 'fa-fill-drip', 'Fondo'], ['marco', 'fa-circle-notch', 'Marco y efectos'], ['medida', 'fa-ruler-combined', 'Medidas']];
  function drawPanels() { drawLeft(); drawRight(); drawStageFoot(); }
  function sw(path, label, val, extra = '') { return `<label class="pf-f"><span>${label}</span><input type="color" data-k="${path}" value="${esc(val)}"${extra}></label>`; }
  function sl(path, label, val, min, max, step = 1) {
    return `<label class="pf-sl"><span>${label}<b data-out="${path}">${val}</b></span><input type="range" data-k="${path}" min="${min}" max="${max}" step="${step}" value="${val}"></label>`;
  }
  function drawLeft() {
    const S = ST.S, hasPhoto = !!S.photo, cut = !!S.photo?.cutToken;
    let body = '';
    if (ST.tab === 'ia') body = aiPanel();
    else if (ST.tab === 'plantillas') {
      body = `<div class="pf-blk"><h4>Plantillas</h4><p class="pf-hint" style="margin:0 0 10px">Cambian el fondo, el marco, los efectos y la medida, y dejan tu foto. Después se puede tocar todo (Ctrl+Z para volver).</p>
          ${hasPhoto && !cut ? '<div class="pf-note" style="margin:0 0 10px"><i class="fa-solid fa-circle-info"></i><span>Para que se vea el fondo de la plantilla detrás tuyo, quitá el fondo de la foto. <button type="button" class="pf-link" data-a="cut-go">Quitar fondo</button></span></div>' : ''}
          ${tplGrid(ST.etcat, 'tpl')}</div>
        ${S.tpl ? `<div class="pf-blk"><h4>Colores de la plantilla</h4><div class="pf-row"><label class="pf-f"><span>Color principal</span><input type="color" data-a-input="recolor" value="${esc(S.frame.color || '#ff6600')}"></label>
          ${!['photo', 'none', 'image', 'paper'].includes(S.bg.type) ? sw('bg.color', 'Color del fondo', S.bg.color) : ''}</div><p class="pf-hint">El color principal cambia marco, brillo, insignia y el segundo color del fondo a la vez.</p></div>` : ''}`;
    } else if (ST.tab === 'foto') {
      body = `<div class="pf-blk"><h4>Foto</h4>${hasPhoto ? `<div class="pf-row"><button type="button" class="pf-btn pf-btn-ghost" data-a="replace"><i class="fa-solid fa-arrows-rotate"></i> Cambiar foto</button></div>` : '<button type="button" class="pf-btn" data-a="replace"><i class="fa-solid fa-image"></i> Subir foto</button>'}</div>
        ${hasPhoto ? `<div class="pf-blk"><h4>Quitar el fondo</h4>
          ${cut ? `<p class="pf-hint"><i class="fa-solid fa-circle-check" style="color:#22c55e"></i> Fondo quitado. Elegí uno nuevo en la pestaña Fondo.</p>
            <div class="pf-row"><button type="button" class="pf-btn pf-btn-ghost" data-a="cut-redo"><i class="fa-solid fa-rotate"></i> Volver a calcular</button><button type="button" class="pf-btn pf-btn-ghost" data-a="cut-off"><i class="fa-solid fa-xmark"></i> Usar el fondo original</button></div>`
          : `<p class="pf-hint">Se hace en tu navegador, sin costo. La primera vez descarga el modelo (unos 100 MB) y después queda guardado.</p><button type="button" class="pf-btn" data-a="cut"><i class="fa-solid fa-scissors"></i> Quitar fondo</button>`}
          <div class="pf-prog" id="pf-prog" hidden><i></i><span></span></div></div>
        <div class="pf-blk"><h4>Encuadre</h4>${sl('photo.scale', 'Zoom', S.photo.scale, .3, 4, .01)}${sl('photo.rot', 'Rotación', S.photo.rot, -180, 180, 1)}
          <div class="pf-row"><button type="button" class="pf-btn pf-btn-ghost" data-a="flip"><i class="fa-solid fa-left-right"></i> Espejar</button><button type="button" class="pf-btn pf-btn-ghost" data-a="center"><i class="fa-solid fa-crosshairs"></i> Centrar</button></div>
          <p class="pf-hint">Arrastrá la foto en la vista para moverla y usá la ruedita para acercarla.</p></div>` : ''}`;
    } else if (ST.tab === 'fondo') {
      const B = S.bg;
      const opt = (id, name) => `<button type="button" class="pf-chip${B.type === id ? ' on' : ''}" data-a="bg" data-v="${id}">${esc(name)}</button>`;
      body = `${hasPhoto && !cut ? '<div class="pf-note"><i class="fa-solid fa-circle-info"></i><span>Para ver un fondo nuevo detrás de la persona, primero quitá el fondo de la foto (pestaña Foto).</span></div>' : ''}
        <div class="pf-blk"><h4>Fondo</h4><div class="pf-chips">${hasPhoto ? opt('photo', 'El de la foto') : ''}${opt('none', 'Transparente')}</div>
        ${R.BG_GROUPS.map(([g, list]) => `<div class="pf-sub">${esc(g)}</div><div class="pf-chips">${list.map(([id, n]) => opt(id, n)).join('')}</div>`).join('')}
        <div class="pf-sub">Propio</div><div class="pf-chips">${opt('image', B.token ? 'Mi imagen' : 'Subir imagen…')}</div></div>
        ${!['photo', 'none', 'image'].includes(B.type) ? `<div class="pf-blk"><h4>Colores</h4><div class="pf-row">${sw('bg.color', 'Color 1', B.color)}${['solid', 'paper', 'stars'].includes(B.type) ? '' : sw('bg.color2', 'Color 2', B.color2)}</div>
          ${B.type === 'gradient' ? sl('bg.angle', 'Ángulo', B.angle, 0, 360) : ''}${['blobs', 'hex', 'stars', 'nebula', 'noise'].includes(B.type) ? '<button type="button" class="pf-btn pf-btn-ghost" data-a="reseed"><i class="fa-solid fa-dice"></i> Otra variante</button>' : ''}</div>` : ''}
        ${B.type === 'image' ? `<div class="pf-blk"><h4>Mi imagen de fondo</h4><button type="button" class="pf-btn pf-btn-ghost" data-a="bg-upload"><i class="fa-solid fa-upload"></i> ${B.token ? 'Cambiar imagen' : 'Subir imagen'}</button>
          ${B.token ? sl('bg.scale', 'Escala', B.scale, .5, 3, .01) + sl('bg.x', 'Posición horizontal', B.x, -.5, .5, .01) + sl('bg.y', 'Posición vertical', B.y, -.5, .5, .01) + sl('bg.blur', 'Desenfoque', B.blur, 0, 100) : ''}</div>` : ''}
        ${B.type === 'photo' && cut ? `<div class="pf-blk"><h4>Fondo de la foto</h4>${sl('subject.blurBg', 'Desenfocar el fondo', S.subject.blurBg, 0, 100)}</div>` : ''}
        ${!['photo', 'none'].includes(B.type) ? `<div class="pf-blk">${sl('bg.dim', 'Oscurecer', B.dim, 0, 100)}</div>` : ''}`;
    } else if (ST.tab === 'marco') {
      const F = S.frame, X = S.fx, BD = S.badge;
      body = `<div class="pf-blk"><h4>Marco</h4><div class="pf-chips">${Object.entries(R.FRAMES).map(([k, n]) => `<button type="button" class="pf-chip${F.type === k ? ' on' : ''}" data-a="frame" data-v="${k}">${esc(n)}</button>`).join('')}</div>
          ${F.type !== 'none' ? `<div class="pf-row">${sw('frame.color', 'Color', F.color)}${['gradient', 'glow', 'gamer'].includes(F.type) ? sw('frame.color2', 'Color 2', F.color2) : ''}</div>${sl('frame.width', 'Grosor', F.width, 1, 12, .5)}${['neon', 'glow', 'gamer', 'minimal'].includes(F.type) ? sl('frame.intensity', 'Intensidad', F.intensity, 0, 100) : ''}` : ''}</div>
        <div class="pf-blk"><h4>Forma</h4><div class="pf-chips">${[['circle', 'Círculo'], ['rounded', 'Redondeada'], ['square', 'Cuadrada']].map(([k, n]) => `<button type="button" class="pf-chip${S.shape === k ? ' on' : ''}" data-a="shape" data-v="${k}">${n}</button>`).join('')}</div></div>
        <div class="pf-blk"><h4>Efectos</h4>
          ${tg('fx.sparkles.on', 'Brillos y partículas', X.sparkles.on)}${X.sparkles.on ? `<div class="pf-chips">${[['stars', 'Estrellas'], ['dots', 'Puntos'], ['bokeh', 'Bokeh']].map(([k, n]) => `<button type="button" class="pf-chip${X.sparkles.style === k ? ' on' : ''}" data-a="sparkstyle" data-v="${k}">${n}</button>`).join('')}</div><div class="pf-row">${sw('fx.sparkles.color', 'Color', X.sparkles.color)}</div>${sl('fx.sparkles.amount', 'Cantidad', X.sparkles.amount, 0, 100)}` : ''}
          ${cut ? tg('fx.glow.on', 'Brillo alrededor de la persona', X.glow.on) + (X.glow.on ? `<div class="pf-row">${sw('fx.glow.color', 'Color', X.glow.color)}</div>${sl('fx.glow.size', 'Tamaño', X.glow.size, 0, 100)}` : '')
            + tg('fx.shadow.on', 'Sombra detrás de la persona', X.shadow.on) + (X.shadow.on ? `<div class="pf-row">${sw('fx.shadow.color', 'Color', X.shadow.color)}</div>${sl('fx.shadow.size', 'Tamaño', X.shadow.size, 0, 100)}` : '')
            : '<p class="pf-hint">Con el fondo quitado se suman brillo y sombra alrededor de la persona.</p>'}</div>
        <div class="pf-blk"><h4>Insignia</h4>${tg('badge.on', 'Mostrar insignia', BD.on)}
          ${BD.on ? `<div class="pf-chips">${[['check', 'Verificado'], ['dot', 'En línea'], ['text', 'Texto o emoji']].map(([k, n]) => `<button type="button" class="pf-chip${BD.kind === k ? ' on' : ''}" data-a="badgekind" data-v="${k}">${n}</button>`).join('')}</div>
            ${BD.kind === 'text' ? `<label class="pf-f"><span>Texto (hasta 3)</span><input class="pf-in" data-k="badge.text" maxlength="3" value="${esc(BD.text)}"></label>` : ''}
            <div class="pf-row">${sw('badge.color', 'Color', BD.color)}</div>${sl('badge.size', 'Tamaño', BD.size, 12, 40)}
            <div class="pf-chips">${[['br', 'Abajo der.'], ['bl', 'Abajo izq.'], ['tr', 'Arriba der.'], ['tl', 'Arriba izq.']].map(([k, n]) => `<button type="button" class="pf-chip${BD.pos === k ? ' on' : ''}" data-a="badgepos" data-v="${k}">${n}</button>`).join('')}</div>` : ''}</div>`;
    } else {
      body = `<div class="pf-blk"><h4>Medida de la red</h4><p class="pf-hint">Elegí para qué red es: la exportación toma esa medida (se puede cambiar al exportar). La guía circular muestra lo que va a recortar la red.</p>
        <div class="pf-presets">${Object.entries(R.PRESETS).map(([k, p]) => `<button type="button" class="pf-preset${S.preset === k ? ' on' : ''}" data-a="preset" data-v="${k}"><i class="${p[1]}" style="color:${p[2]}"></i><b>${esc(p[0])}</b><small>${k === 'custom' ? 'a elección' : p[3] + ' × ' + p[3] + ' px'}</small></button>`).join('')}</div>
        ${tg('guide', 'Mostrar la guía de recorte', S.guide)}</div>`;
    }
    $('#pf-left').innerHTML = `<nav class="pf-tabs">${TABS.map(([k, i, n]) => `<button type="button" class="${ST.tab === k ? 'on' : ''}" data-a="tab" data-v="${k}"><i class="fa-solid ${i}"></i><span>${n}</span></button>`).join('')}</nav><div class="pf-panel">${body}</div>`;
  }
  const tg = (path, label, on) => `<label class="pf-tg"><input type="checkbox" data-k="${path}"${on ? ' checked' : ''}><span></span>${esc(label)}</label>`;
  function drawRight() {
    const S = ST.S, A = S.adj;
    $('#pf-right').innerHTML = `<div class="pf-panel"><div class="pf-blk"><h4>Ajustes</h4>
      ${sl('adj.brightness', 'Brillo', A.brightness, -100, 100)}${sl('adj.exposure', 'Exposición', A.exposure, -100, 100)}${sl('adj.contrast', 'Contraste', A.contrast, -100, 100)}
      ${sl('adj.saturation', 'Saturación', A.saturation, -100, 100)}${sl('adj.temperature', 'Temperatura', A.temperature, -100, 100)}${sl('adj.sharpness', 'Nitidez', A.sharpness, 0, 100)}
      ${sl('adj.blur', 'Desenfoque', A.blur, 0, 100)}${sl('adj.vignette', 'Viñeta', A.vignette, 0, 100)}
      <button type="button" class="pf-link" data-a="adj-reset"><i class="fa-solid fa-rotate-left"></i> Volver a cero</button></div>
      <div class="pf-blk"><h4>Filtros</h4><div class="pf-filters">${Object.entries(R.FILTERS).map(([k, f]) => `<button type="button" class="pf-chip${S.filter === k ? ' on' : ''}" data-a="filter-set" data-v="${k}">${esc(f[0])}</button>`).join('')}</div></div>
      <div class="pf-blk"><button type="button" class="pf-link" data-a="restore-all"><i class="fa-solid fa-eraser"></i> Restaurar todos los cambios</button></div></div>`;
  }
  function drawStageFoot() {
    const p = presetOf(ST.S);
    $('#pf-stage-f').innerHTML = `<span><i class="${p[1]}" style="color:${p[2]}"></i> ${esc(p[0])} · ${ST.S.preset === 'custom' ? 'medida a elección' : p[3] + ' × ' + p[3] + ' px'}</span><span class="pf-hint">Ctrl+Z deshacer · Ctrl+S guardar versión · Ctrl+E exportar · flechas mover</span>`;
  }

  // ── Painting ────────────────────────────────────────
  let raf = 0;
  function paint() { cancelAnimationFrame(raf); raf = requestAnimationFrame(() => {
    const cv = $('#pf-cv'); if (!cv) return;
    const N = cv.width;
    if (!ST.S.photo) R.draw(cv, { ...ST.S, photo: SIL_PHOTO }, { photo: R.silhouette(), cut: R.silhouette() }, { plain: ST.before });   // preview only: exports have no silhouette
    else R.draw(cv, ST.S, ST.imgs, { plain: ST.before });
    $('#pf-guide').className = 'pf-guide' + (ST.S.guide ? ' on ' + ST.S.shape : '');
  }); }

  // ── State changes + history ─────────────────────────
  const get = (o, p) => p.split('.').reduce((a, k) => a?.[k], o);
  function set(o, p, v) { const ks = p.split('.'), last = ks.pop(); const t = ks.reduce((a, k) => (a[k] ??= {}), o); t[last] = v; }
  function changed(redraw = false) {
    paint(); markDirty();
    clearTimeout(ST.hTimer); ST.hTimer = setTimeout(commit, 350);
    if (redraw) drawPanels();
  }
  function commit() {
    const j = JSON.stringify(ST.S); if (j === ST.cur) return;
    ST.past.push(ST.cur); if (ST.past.length > 100) ST.past.shift(); ST.future = []; ST.cur = j;
  }
  function histGo(dir) {
    commit();
    const from = dir < 0 ? ST.past : ST.future, to = dir < 0 ? ST.future : ST.past;
    if (!from.length) return toast(dir < 0 ? 'No hay nada para deshacer.' : 'No hay nada para rehacer.');
    to.push(ST.cur); ST.cur = from.pop(); ST.S = JSON.parse(ST.cur);
    loadImgs().then(() => { drawPanels(); paint(); markDirty(); });
  }
  function markDirty() { ST.dirty = true; saveBadge(); clearTimeout(ST.timer); ST.timer = setTimeout(save, 2500); }
  function saveBadge() { const b = $('#pf-save'); if (!b) return; b.innerHTML = ST.saving ? '<i class="fa-solid fa-spinner fa-spin"></i> Guardando…' : ST.dirty ? '<i class="fa-regular fa-circle"></i> Sin guardar' : '<i class="fa-solid fa-check"></i> Guardado'; }
  async function thumbToken() {
    const c = document.createElement('canvas'); c.width = c.height = 256; R.draw(c, ST.S, ST.imgs, { clip: true });
    const b = await toBlob(c, 'image/png'); if (!b) return '';
    try { return (await upload(b, 'thumb', 'thumb.png')).token; } catch (e) { return ''; }
  }
  /** Autosave (and manual version with label). The thumbnail is renewed with each version or every 2 minutes. */
  async function save(version = '') {
    if (!ST.id || ST.saving) return;
    clearTimeout(ST.timer); commit(); ST.saving = true; saveBadge();
    try {
      const body = { id: ST.id, name: ST.name, state: ST.S, preset: ST.S.preset };
      if (version || !ST.thumbAt || Date.now() - ST.thumbAt > 120000) { body.thumb_token = await thumbToken(); ST.thumbAt = Date.now(); }
      if (version) body.version = version;
      await api('pf_save', body, true); ST.dirty = false; ST.savedAt = Date.now();
      if (version) toast('Versión guardada');
    } catch (e) { toast('No se pudo guardar: se reintenta en un momento.', true); ST.timer = setTimeout(save, 8000); }
    finally { ST.saving = false; saveBadge(); }
  }

  // ── Canvas interaction: drag, wheel, arrows ─────────
  function bindCanvas() {
    const cv = $('#pf-cv');
    cv.addEventListener('pointerdown', e => { if (!ST.S.photo) return; cv.setPointerCapture(e.pointerId); ST.drag = { x: e.clientX, y: e.clientY, px: ST.S.photo.x, py: ST.S.photo.y, w: cv.getBoundingClientRect().width }; cv.classList.add('drag'); });
    cv.addEventListener('pointermove', e => { const d = ST.drag; if (!d) return; ST.S.photo.x = +(d.px + (e.clientX - d.x) / d.w).toFixed(4); ST.S.photo.y = +(d.py + (e.clientY - d.y) / d.w).toFixed(4); paint(); });
    const end = () => { if (!ST.drag) return; ST.drag = null; cv.classList.remove('drag'); changed(); };
    cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);
    cv.addEventListener('wheel', e => { if (!ST.S.photo) return; e.preventDefault(); const s = ST.S.photo.scale * (e.deltaY < 0 ? 1.06 : 1 / 1.06); ST.S.photo.scale = +Math.max(.3, Math.min(4, s)).toFixed(3); const o = $('[data-out="photo.scale"]'), i = $('[data-k="photo.scale"]'); if (o) o.textContent = ST.S.photo.scale; if (i) i.value = ST.S.photo.scale; changed(); }, { passive: false });
  }

  // ── Background removal in the browser (BiRefNet lite 512, MIT, with Transformers.js) ──
  const BGR_MODEL = 'studioludens/birefnet-lite-512', BGR_REV = '962e67969ec6acec9c19fab76acee4f5e22e990c';
  let BGR = null, BGR_PROG = null;
  function bgrLoad(cpu = false) {
    BGR ||= (async () => {
      const T = await import('https://cdn.jsdelivr.net/npm/@huggingface/transformers@4.3.1');
      const gpu = !cpu && !!navigator.gpu && !!(await navigator.gpu.requestAdapter().catch(() => null));
      const progress_callback = p => { if (p.status === 'progress' && /\.onnx$/.test(p.file || '')) BGR_PROG?.('fetch', p.loaded, p.total); };
      const opt = { revision: BGR_REV, progress_callback };
      const model = await T.AutoModel.from_pretrained(BGR_MODEL, { ...opt, dtype: gpu ? 'fp16' : 'fp32', device: gpu ? 'webgpu' : 'wasm' })
        .catch(e => { if (!gpu) throw e; return T.AutoModel.from_pretrained(BGR_MODEL, { ...opt, dtype: 'fp32', device: 'wasm' }); });
      const processor = await T.AutoProcessor.from_pretrained(BGR_MODEL, opt);
      return { T, model, processor, gpu };
    })().catch(e => { BGR = null; throw e; });
    return BGR;
  }
  async function removeBg(img, onProg) {
    BGR_PROG = onProg;
    try {
      const src = document.createElement('canvas'); src.width = img.naturalWidth; src.height = img.naturalHeight; src.getContext('2d').drawImage(img, 0, 0);
      const dataUrl = src.toDataURL('image/png');
      let L = await bgrLoad(); onProg?.('compute');
      const image = await L.T.RawImage.fromURL(dataUrl);
      const { pixel_values } = await L.processor(image);
      let out;
      try { out = await L.model({ input_image: pixel_values }); }
      catch (e) { if (!L.gpu) throw e; BGR = null; L = await bgrLoad(true); onProg?.('compute'); out = await L.model({ input_image: pixel_values }); }
      const mask = await L.T.RawImage.fromTensor(out.output_image[0].sigmoid().mul(255).to('uint8')).resize(image.width, image.height);
      const x = src.getContext('2d'), d = x.getImageData(0, 0, src.width, src.height), px = d.data, md = mask.data, ch = mask.channels;
      for (let i = 0, n = src.width * src.height; i < n; i++) px[i * 4 + 3] = Math.min(px[i * 4 + 3], md[i * ch]);
      x.putImageData(d, 0, 0);
      return await toBlob(src, 'image/png');
    } finally { BGR_PROG = null; }
  }
  async function doCut() {
    if (!ST.imgs.photo) return;
    const pg = $('#pf-prog'); pg.hidden = false; $$('[data-a="cut"],[data-a="cut-redo"]').forEach(b => { b.disabled = true; });
    const say = (t, pct) => { pg.querySelector('span').textContent = t; pg.querySelector('i').style.width = (pct ?? 100) + '%'; pg.classList.toggle('busy', pct == null); };
    try {
      const blob = await removeBg(ST.imgs.photo, (st, a, b) => st === 'fetch' ? say(`Descargando el modelo… ${b ? Math.round(a / b * 100) : 0}%`, b ? a / b * 100 : 0) : say('Quitando el fondo…'));
      say('Guardando…');
      const up = await upload(blob, 'cutout', 'sin-fondo.png');
      ST.S.photo.cutToken = up.token; ST.imgs.cut = await loadImg(fileUrl(up.token));
      if (ST.S.bg.type === 'photo') ST.S.bg.type = 'gradient';
      changed(true); toast('Fondo quitado');
    } catch (e) { pg.hidden = true; toast('No se pudo quitar el fondo en este navegador: ' + (e.message || 'error'), true); $$('[data-a="cut"],[data-a="cut-redo"]').forEach(b => { b.disabled = false; }); }
  }

  // ── Export ──────────────────────────────────────────
  function exportDialog() {
    const p = presetOf(ST.S), base = ST.S.preset === 'custom' ? 1024 : p[3];
    modal(`<div class="pf-dg-h"><h2><i class="fa-solid fa-download"></i> Exportar</h2><button type="button" class="pf-ico" data-a="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="pf-dg-b pf-exp"><div class="pf-exp-prev"><img id="pf-exp-img" alt="Vista previa"><small id="pf-exp-info"></small></div>
      <div class="pf-exp-f">
        <label class="pf-f"><span>Formato</span><select class="pf-in" id="ex-fmt"><option value="png">PNG (con transparencia)</option><option value="jpg">JPG (más liviano)</option><option value="webp">WebP</option></select></label>
        <label class="pf-f"><span>Tamaño</span><select class="pf-in" id="ex-size"><option value="${base}">${esc(p[0])}: ${base} × ${base}</option>${[512, 1024, 2048].filter(n => n !== base).map(n => `<option value="${n}">${n} × ${n}</option>`).join('')}<option value="custom">Otro…</option></select></label>
        <label class="pf-f" id="ex-custom" hidden><span>Lado en píxeles</span><input class="pf-in" type="number" id="ex-px" min="64" max="4096" value="${base}"></label>
        <label class="pf-tg"><input type="checkbox" id="ex-clip"${ST.S.shape !== 'square' ? ' checked' : ''}><span></span>Recortar la forma (${ST.S.shape === 'circle' ? 'círculo' : ST.S.shape === 'rounded' ? 'redondeada' : 'cuadrada'}) con las esquinas transparentes</label>
        <label class="pf-sl" id="ex-q-w"><span>Calidad<b id="ex-q-o">90</b></span><input type="range" id="ex-q" min="40" max="100" value="90"></label>
        <p class="pf-hint" id="ex-note"></p></div></div>
      <div class="pf-dg-f pf-exp-more"><button type="button" class="pf-btn pf-btn-ghost" data-a="to-avatar" title="La pone como tu foto de perfil en Cronos"><i class="fa-solid fa-circle-user"></i> Usar como mi foto en Cronos</button>
        <button type="button" class="pf-btn pf-btn-ghost" data-a="to-post" title="La suma a los archivos de una publicación de Social Studio"><i class="fa-solid fa-paperclip"></i> Mandar a una publicación</button>
        <span class="sp"></span><button type="button" class="pf-btn pf-btn-ghost" data-a="close">Cancelar</button><button type="button" class="pf-btn" data-a="do-export"><i class="fa-solid fa-download"></i> Bajar</button></div>`, 'wide');
    ['ex-fmt', 'ex-size', 'ex-px', 'ex-clip', 'ex-q'].forEach(id => $('#' + id).addEventListener('input', expPreview));
    expPreview();
  }
  function expOpts() {
    const fmt = $('#ex-fmt').value, sizeSel = $('#ex-size').value, n = sizeSel === 'custom' ? Math.max(64, Math.min(4096, +$('#ex-px').value || 512)) : +sizeSel;
    const clip = $('#ex-clip').checked, q = +$('#ex-q').value / 100;
    return { fmt, n, clip, q, mime: fmt === 'png' ? 'image/png' : fmt === 'jpg' ? 'image/jpeg' : 'image/webp' };
  }
  async function render(o) {
    const c = document.createElement('canvas'); c.width = c.height = o.n;
    const fill = o.fmt === 'jpg' ? (ST.S.bg.type === 'solid' ? ST.S.bg.color : '#ffffff') : null;
    R.draw(c, ST.S, ST.imgs, { clip: o.clip && o.fmt !== 'jpg', fill });
    return toBlob(c, o.mime, o.fmt === 'png' ? undefined : o.q);
  }
  let expT = 0;
  function expPreview() {
    const o = expOpts();
    $('#ex-custom').hidden = $('#ex-size').value !== 'custom'; $('#ex-q-w').hidden = o.fmt === 'png'; $('#ex-q-o').textContent = Math.round(o.q * 100);
    $('#ex-note').textContent = o.fmt === 'jpg' ? 'JPG no guarda transparencia: lo vacío sale ' + (ST.S.bg.type === 'solid' ? 'del color del fondo.' : 'blanco.') : o.clip ? 'Las esquinas quedan transparentes.' : '';
    clearTimeout(expT); expT = setTimeout(async () => {
      const b = await render(o); if (!b) return;
      const img = $('#pf-exp-img'); if (!img) return; if (img.src) URL.revokeObjectURL(img.src); img.src = URL.createObjectURL(b);
      $('#pf-exp-info').textContent = `${o.n} × ${o.n} px · ${o.fmt.toUpperCase()} · ${b.size > 1048576 ? (b.size / 1048576).toFixed(2) + ' MB' : Math.max(1, Math.round(b.size / 1024)) + ' KB'}`;
    }, 120);
  }
  async function doExport() {
    const o = expOpts(), b = await render(o); if (!b) return toast('Este navegador no pudo generar ese formato.', true);
    const a = document.createElement('a'), name = (ST.name || 'perfil').replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '').toLowerCase() || 'perfil';
    a.href = URL.createObjectURL(b); a.download = `${name}-${ST.S.preset}-${o.n}.${o.fmt}`; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000); closeModal(); toast('Imagen exportada');
  }

  // ── Versions ────────────────────────────────────────
  async function historyDialog() {
    await save();
    let v = []; try { v = (await api('pf_versions', { id: ST.id })).items; } catch (e) { return; }
    modal(`<div class="pf-dg-h"><h2><i class="fa-solid fa-clock-rotate-left"></i> Historial</h2><button type="button" class="pf-ico" data-a="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="pf-dg-b"><p class="pf-hint">Las versiones se guardan con "Guardar versión" (Ctrl+S). Volver a una guarda antes la actual, así no se pierde nada.</p>
      <ul class="pf-vers">${v.map(x => `<li><div><b>${esc(x.label)}</b><small>${esc(new Date(x.at.replace(' ', 'T')).toLocaleString('es-AR'))}</small></div><button type="button" class="pf-btn pf-btn-ghost" data-a="restore" data-id="${x.id}">Volver a esta</button></li>`).join('') || '<li>Sin versiones todavía.</li>'}</ul></div>`);
  }

  // ── Events ──────────────────────────────────────────
  function pickFile(cb) { const f = $('#pf-file'); f.value = ''; f.onchange = () => { if (f.files[0]) cb(f.files[0]); }; f.click(); }
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-a]'); if (!b) { if (e.target.id === 'pf-modal') closeModal(); return; }
    const a = b.dataset.a, id = +b.dataset.id || 0, v = b.dataset.v, S = ST.S;
    if (b.closest('.pf-card-acts')) e.stopPropagation();
    switch (a) {
      case 'open': if (!e.target.closest('.pf-card-acts')) location.hash = '#p/' + id; break;
      case 'new-photo': pickFile(f => createFrom(f).catch(() => {})); break;
      case 'new-blank': { const s = R.defaults(); create(s, 'Imagen de perfil').catch(() => {}); break; }
      case 'filter': ST.filter = b.dataset.f; drawHome(); break;
      case 'fav': { const p = ST.items.find(x => x.id === id); try { ST.items = (await api('pf_set', { id, favorite: !p.favorite })).items; drawHome(); } catch (err) {} break; }
      case 'dup': try { ST.items = (await api('pf_duplicate', { id })).items; drawHome(); toast('Duplicado'); } catch (err) {} break;
      case 'ren': { const p = ST.items.find(x => x.id === id); const n = prompt('Nombre del proyecto', p.name); if (n === null || !n.trim()) return; try { ST.items = (await api('pf_set', { id, name: n.trim() })).items; drawHome(); } catch (err) {} break; }
      case 'del': if (!confirm('¿Borrar este proyecto? No se puede deshacer.')) return; try { ST.items = (await api('pf_delete', { id })).items; drawHome(); toast('Borrado'); } catch (err) {} break;
      case 'home': await save(); location.hash = ''; break;
      case 'tab': ST.tab = v; drawLeft(); break;
      case 'share': { const p = ST.items.find(x => x.id === id); try { ST.items = (await api('pf_set', { id, shared: !p.shared })).items; ST.gal = await api('pf_gallery', {}, true); drawHome(); toast(p.shared ? 'Sacado de la galería' : 'Compartido en la galería de la agencia'); } catch (err) {} break; }
      case 'gal': {
        const g = (ST.gal?.items || []).find(x => x.id === id); if (!g) return;
        modal(`<div class="pf-dg-h"><h2><i class="fa-solid fa-users"></i> ${esc(g.name)}</h2><button type="button" class="pf-ico" data-a="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
          <div class="pf-dg-b pf-tpl-start"><img src="${esc(g.thumb)}" alt=""><p>Diseño de <b>${esc(g.who)}</b>. Podés usar su fondo, marco, efectos y filtro con tu propia foto (su foto no se comparte).</p></div>
          <div class="pf-dg-f"><span class="sp"></span><button type="button" class="pf-btn" data-a="gal-use" data-id="${g.id}"><i class="fa-solid fa-wand-magic-sparkles"></i> Usar este diseño</button></div>`);
        break; }
      case 'gal-use': try { const look = (await api('pf_gallery_look', { id })).look; const s2 = Object.assign(R.defaults(), look); closeModal(); create(s2, 'Basado en la galería').catch(() => {}); } catch (err) {} break;
      case 'to-avatar': {
        if (!confirm('¿Usar esta imagen como tu foto de perfil en Cronos?')) return;
        const c = document.createElement('canvas'); c.width = c.height = 512; R.draw(c, ST.S, ST.imgs, { clip: true });
        const blob = await toBlob(c, 'image/png'); const fd = new FormData(); fd.append('action', 'update_avatar'); fd.append('avatar_file', blob, 'perfil.png');
        try { const r = await fetch('/toolz/user_config.php', { method: 'POST', body: fd }); const j = await r.json(); if (j.status !== 'success') throw new Error(j.error || 'No se pudo cambiar la foto.'); closeModal(); toast('Listo: es tu foto de perfil en Cronos'); }
        catch (err) { toast(err.message, true); }
        break; }
      case 'to-post': {
        let items = []; try { items = (await api('list', { filters: {} })).items || []; } catch (err) { return; }
        items = items.slice().sort((x, y) => String(y.updated_at || y.date || '').localeCompare(String(x.updated_at || x.date || ''))).slice(0, 60);
        ST.expOpts = expOpts();
        modal(`<div class="pf-dg-h"><h2><i class="fa-solid fa-paperclip"></i> Mandar a una publicación</h2><button type="button" class="pf-ico" data-a="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
          <div class="pf-dg-b"><p class="pf-hint" style="margin:0 0 10px">Se suma a los archivos de la publicación, con el formato y la medida que elegiste (${ST.expOpts.n} px, ${ST.expOpts.fmt.toUpperCase()}).</p>
          ${items.length ? `<ul class="pf-vers">${items.map(x => `<li><div><b>${esc(x.title)}</b><small>${esc(x.date || 'sin fecha')}</small></div><button type="button" class="pf-btn pf-btn-ghost" data-a="to-post-go" data-id="${x.id}">Mandar</button></li>`).join('')}</ul>` : '<p class="pf-hint">No hay publicaciones que puedas ver.</p>'}</div>`);
        break; }
      case 'to-post-go': {
        const o = ST.expOpts || { fmt: 'png', n: 1024, clip: true, q: .9, mime: 'image/png' }; const blob = await render(o);
        const fd = new FormData(); fd.append('action', 'file_upload'); fd.append('id', String(id)); fd.append('file', blob, `${(ST.name || 'perfil').replace(/[^\p{L}\p{N}]+/gu, '-').toLowerCase()}-${o.n}.${o.fmt}`);
        try { const r = await fetch('../api.php', { method: 'POST', headers: { 'X-SS': '1' }, body: fd }); const j = await r.json(); if (!r.ok || j.error) throw new Error(j.error || 'No se pudo mandar.'); closeModal(); toast('Listo: está en los archivos de la publicación'); }
        catch (err) { toast(err.message, true); }
        break; }
      case 'ai-home': aiHome(); break;
      case 'ai-style': ST.aiStyle = ST.aiStyle === v ? '' : v; drawLeft(); break;
      case 'ai-style-cat': ST.aiCat = v; drawLeft(); break;
      case 'home-style': ST.homeStyle = ST.homeStyle === v ? '' : v; $('#ai-gen-styles').innerHTML = styleCards(ST.homeStyle, 'home-style', ST.homeCat || ''); break;
      case 'home-style-cat': ST.homeCat = v; $('#ai-gen-styles').innerHTML = styleCards(ST.homeStyle, 'home-style', v); break;
      case 'ai-transform': aiRun('transform', { style: ST.aiStyle, prompt: $('#ai-tr-extra')?.value || '', qty: +($('#ai-tr-qty')?.value || 1), label: (ST.ai.styles.find(x => x.id === ST.aiStyle) || {}).name }); break;
      case 'ai-chip': { const t = $('#ai-ed-text'); t.value = (t.value.trim() ? t.value.trim().replace(/[,.]$/, '') + ', ' : '') + v; break; }
      case 'ai-edit': { const t = ($('#ai-ed-text')?.value || '').trim(); if (t.length < 3) return toast('Escribí o elegí qué querés cambiar.', true); aiRun('edit', { prompt: t, label: t.slice(0, 40) }); break; }
      case 'ai-upscale': aiRun('upscale', {}); break;
      case 'ai-nobg': aiRun('nobg', {}); break;
      case 'ai-use': { const r = (S.ai || [])[+b.dataset.i]; if (!r) return;
        if (r.op === 'nobg') { S.photo.cutToken = r.token; if (S.bg.type === 'photo') S.bg.type = 'gradient'; }
        else S.photo = { token: r.token, w: r.w, h: r.h, cutToken: null, x: 0, y: 0, scale: 1, rot: 0, flip: false };
        await loadImgs(); changed(true); toast(r.op === 'nobg' ? 'Listo: se usa el recorte de la IA' : 'Listo: esa es la foto ahora (Ctrl+Z para volver)'); break; }
      case 'ai-drop': S.ai.splice(+b.dataset.i, 1); changed(true); break;
      case 'ai-suggest': {
        const mode = v, src = mode === 'gen' ? $('#ai-gen-text') : $('#ai-ed-text'), box = $(mode === 'gen' ? '#ai-ideas-gen' : '#ai-ideas-edit');
        box.innerHTML = '<p class="pf-hint"><i class="fa-solid fa-spinner fa-spin"></i> Pensando ideas…</p>';
        try { const r = await api('pf_ai_suggest', { idea: src.value, style: mode === 'gen' ? ST.homeStyle : ST.aiStyle, mode }); box.innerHTML = ideasHtml(r.ideas, mode); } catch (err) { box.innerHTML = ''; }
        break; }
      case 'ai-idea': { const t = b.dataset.t === 'gen' ? $('#ai-gen-text') : $('#ai-ed-text'); t.value = v; t.focus(); break; }
      case 'ai-generate': {
        const text = $('#ai-gen-text').value.trim(); if (text.length < 4 && !ST.homeStyle) return toast('Describí el avatar o elegí un estilo.', true);
        b.disabled = true; const res = $('#ai-gen-res'); res.innerHTML = '<div class="pf-note"><i class="fa-solid fa-spinner fa-spin"></i><span>Generando con Leonardo…</span></div>';
        try { const r = await api('pf_ai_start', { op: 'generate', prompt: text, style: ST.homeStyle, qty: +$('#ai-gen-qty').value });
          const imgs = await waitJob(r.id);
          res.innerHTML = `<div class="pf-sub">Elegí una para seguir editándola</div><div class="pf-results">${imgs.map(im => `<figure class="pf-res"><img src="${fileUrl(im.token)}" alt=""><div class="acts"><button type="button" class="pf-btn" data-a="ai-gen-open" data-t="${im.token}" data-w="${im.w}" data-h="${im.h}">Usar esta</button></div></figure>`).join('')}</div>`;
        } catch (err) { res.innerHTML = `<div class="pf-note"><i class="fa-solid fa-triangle-exclamation"></i><span>${esc(err.message)}</span></div>`; }
        finally { b.disabled = false; }
        break; }
      case 'ai-gen-open': { const s = R.defaults(); s.bg.type = 'photo'; s.photo = { token: b.dataset.t, w: +b.dataset.w, h: +b.dataset.h, cutToken: null, x: 0, y: 0, scale: 1, rot: 0, flip: false };
        s.ai = [{ token: b.dataset.t, w: +b.dataset.w, h: +b.dataset.h, op: 'generate', label: 'Avatar con IA' }];
        closeModal(); create(s, 'Avatar con IA').catch(() => {}); break; }
      case 'ai-thumbs': {
        if (!confirm('Se generan con Leonardo las miniaturas de los estilos que faltan (una imagen chica por estilo). Gasta créditos de la cuenta. ¿Seguir?')) return;
        try { const r = await api('pf_ai_thumbs'); toast(`Generando ${r.started} miniaturas${r.cost ? ' (' + r.cost + ' créditos)' : ''}…`);
          for (let i = 0; i < 60; i++) { await new Promise(rr => setTimeout(rr, 4000)); const p = await api('pf_ai_thumbs_poll', {}, true); if (!p.pending) break; }
          ST.ai = null; await aiMeta(); drawLeft(); toast('Miniaturas listas'); } catch (err) {}
        break; }
      case 'tpl': { const keep = ST.S; ST.S = R.applyTemplate(keep, v); changed(true); toast('Plantilla "' + (R.TEMPLATES.find(t => t.id === v) || {}).name + '" aplicada (Ctrl+Z para volver)'); break; }
      case 'tpl-cat': ST.etcat = v; drawLeft(); break;
      case 'home-tpl-cat': ST.tcat = v; drawHome(); break;
      case 'home-tpl': {
        const t = R.TEMPLATES.find(x => x.id === v); if (!t) return;
        modal(`<div class="pf-dg-h"><h2><i class="fa-solid fa-swatchbook"></i> ${esc(t.name)}</h2><button type="button" class="pf-ico" data-a="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
          <div class="pf-dg-b pf-tpl-start"><img src="${tplThumb(t.id)}" alt=""><p>Subí tu foto y se arma con esta plantilla. Para que se vea el fondo de la plantilla detrás tuyo, después tocá "Quitar fondo".</p></div>
          <div class="pf-dg-f"><span class="sp"></span><button type="button" class="pf-btn pf-btn-ghost" data-a="tpl-blank" data-v="${t.id}">Probar sin foto</button><button type="button" class="pf-btn" data-a="tpl-photo" data-v="${t.id}"><i class="fa-solid fa-image"></i> Subir mi foto</button></div>`);
        break;
      }
      case 'tpl-blank': closeModal(); create(R.applyTemplate(R.defaults(), v), (R.TEMPLATES.find(t => t.id === v) || {}).name || 'Imagen de perfil').catch(() => {}); break;
      case 'tpl-photo': closeModal(); pickFile(f => createFrom(f, v).catch(() => {})); break;
      case 'cut-go': ST.tab = 'foto'; drawLeft(); doCut(); break;
      case 'undo': histGo(-1); break;
      case 'redo': histGo(1); break;
      case 'version': save('Versión guardada'); break;
      case 'export': exportDialog(); break;
      case 'history': historyDialog(); break;
      case 'close': closeModal(); break;
      case 'do-export': doExport(); break;
      case 'restore': try { const p = (await api('pf_restore', { id: ST.id, version_id: id })).project; ST.S = Object.assign(R.defaults(), p.state); await loadImgs(); closeModal(); commit(); drawPanels(); paint(); toast('Listo: volviste a esa versión'); } catch (err) {} break;
      case 'replace': pickFile(async f => {
          let ff = f; try { if (window.g7PrepImage) ff = await window.g7PrepImage(f, { max: 2048, maxBytes: 3 * 1024 * 1024 }); } catch (err) {}
          try { const up = await upload(ff, 'photo', ff.name || 'foto.jpg');
            ST.S.photo = { token: up.token, w: up.w, h: up.h, cutToken: null, x: 0, y: 0, scale: 1, rot: 0, flip: false };
            if (!['photo'].includes(ST.S.bg.type) && !S.photo) ST.S.bg.type = 'photo';
            await loadImgs(); changed(true); } catch (err) {} }); break;
      case 'cut': case 'cut-redo': doCut(); break;
      case 'cut-off': S.photo.cutToken = null; ST.imgs.cut = null; S.bg.type = 'photo'; changed(true); break;
      case 'flip': S.photo.flip = !S.photo.flip; changed(); break;
      case 'center': Object.assign(S.photo, { x: 0, y: 0, scale: 1, rot: 0 }); changed(true); break;
      case 'bg': if (v === 'image' && !S.bg.token) { pickFile(async f => { let ff = f; try { if (window.g7PrepImage) ff = await window.g7PrepImage(f, { max: 2048 }); } catch (err) {} try { const up = await upload(ff, 'bg', ff.name || 'fondo.jpg'); Object.assign(S.bg, { type: 'image', token: up.token, w: up.w, h: up.h, x: 0, y: 0, scale: 1 }); await loadImgs(); changed(true); } catch (err) {} }); break; }
        S.bg.type = v; changed(true); break;
      case 'bg-upload': pickFile(async f => { let ff = f; try { if (window.g7PrepImage) ff = await window.g7PrepImage(f, { max: 2048 }); } catch (err) {} try { const up = await upload(ff, 'bg', ff.name || 'fondo.jpg'); Object.assign(S.bg, { type: 'image', token: up.token, w: up.w, h: up.h, x: 0, y: 0, scale: 1 }); await loadImgs(); changed(true); } catch (err) {} }); break;
      case 'reseed': S.bg.seed = Math.floor(Math.random() * 99999) + 1; changed(); break;
      case 'frame': S.frame.type = v; changed(true); break;
      case 'shape': S.shape = v; changed(true); break;
      case 'sparkstyle': S.fx.sparkles.style = v; changed(true); break;
      case 'badgekind': S.badge.kind = v; changed(true); break;
      case 'badgepos': S.badge.pos = v; changed(true); break;
      case 'preset': S.preset = v; changed(true); break;
      case 'filter-set': S.filter = v; changed(); $$('[data-a="filter-set"]').forEach(x => x.classList.toggle('on', x.dataset.v === v)); break;
      case 'adj-reset': S.adj = R.defaults().adj; changed(true); break;
      case 'restore-all': if (!confirm('¿Volver la foto a como estaba? Se sacan ajustes, filtro, fondo nuevo, marco y efectos (se puede deshacer).')) return;
        { const d = R.defaults(); Object.assign(S, { adj: d.adj, filter: 'none', frame: d.frame, fx: d.fx, badge: d.badge, subject: d.subject }); S.bg = Object.assign(d.bg, { type: S.photo ? 'photo' : 'gradient' }); if (S.photo) Object.assign(S.photo, { x: 0, y: 0, scale: 1, rot: 0, flip: false }); changed(true); } break;
    }
  });
  // "Before": while pressed
  document.addEventListener('pointerdown', e => { if (e.target.closest('[data-a="before"]')) { ST.before = true; paint(); } });
  document.addEventListener('pointerup', () => { if (ST.before) { ST.before = false; paint(); } });
  // Sliders, colors, toggles
  document.addEventListener('input', e => {
    if (e.target.dataset?.aInput === 'recolor' && ST.S) { R.recolor(ST.S, e.target.value); changed(); return; }
    if (e.target.dataset?.aInput === 'gallery') { api('pf_gallery_set', { on: e.target.checked }).then(() => home()).catch(() => { e.target.checked = !e.target.checked; }); return; }
    const k = e.target.dataset?.k; if (!k || !ST.S) return;
    let v = e.target.type === 'checkbox' ? e.target.checked : e.target.type === 'range' ? +e.target.value : e.target.value;
    set(ST.S, k, v);
    const o = $(`[data-out="${k}"]`); if (o) o.textContent = v;
    changed(e.target.type === 'checkbox');
  });
  document.addEventListener('keydown', e => {
    if (ST.view !== 'editor') return;
    if (e.target.matches('input,textarea,select') && !(e.ctrlKey || e.metaKey)) return;
    const k = e.key.toLowerCase();
    if ((e.ctrlKey || e.metaKey) && k === 'z' && !e.shiftKey) { e.preventDefault(); histGo(-1); }
    else if ((e.ctrlKey || e.metaKey) && (k === 'y' || (k === 'z' && e.shiftKey))) { e.preventDefault(); histGo(1); }
    else if ((e.ctrlKey || e.metaKey) && k === 's') { e.preventDefault(); save('Versión guardada'); }
    else if ((e.ctrlKey || e.metaKey) && k === 'e') { e.preventDefault(); exportDialog(); }
    else if (e.key === 'Escape' && !$('#pf-modal').hidden) closeModal();
    else if (ST.S.photo && ['arrowleft', 'arrowright', 'arrowup', 'arrowdown'].includes(k) && !e.target.matches('input,textarea,select')) {
      e.preventDefault(); const st = e.shiftKey ? .02 : .005; if (k === 'arrowleft') ST.S.photo.x -= st; if (k === 'arrowright') ST.S.photo.x += st; if (k === 'arrowup') ST.S.photo.y -= st; if (k === 'arrowdown') ST.S.photo.y += st; changed();
    }
  });
  window.addEventListener('beforeunload', e => { if (ST.view === 'editor' && ST.dirty) { save(); e.preventDefault(); e.returnValue = ''; } });

  function route() { const m = location.hash.match(/^#p\/(\d+)/); if (m) { if (ST.id !== +m[1]) open(+m[1]); } else if (ST.view !== 'home' || !ST.items.length) home(); }
  window.addEventListener('hashchange', route);
  route();
})();
