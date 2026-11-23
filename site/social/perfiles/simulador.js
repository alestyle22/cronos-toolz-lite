/* social/perfiles/simulador.js — Vista en redes: arma una maqueta de perfil de cada red (no es la red real: es una
 * aproximación de su diseño y sus recortes) con la foto, la portada y los datos que elige la persona, en computadora,
 * tablet o celular. La foto y la portada se mueven arrastrando y se acercan con el control; las zonas seguras marcan
 * lo que se tapa o se recorta. Las imágenes del dispositivo no se suben: quedan en este navegador (localStorage). */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const KEY = 'g7_sim_v1';
  /* Medidas: rec = recomendada para subir; min = por debajo se ve borrosa; ratio de la portada como se muestra en cada equipo.
     Fuentes: guías de cada red a 2026 (pueden cambiar sin aviso: es una referencia). */
  const NETS = {
    facebook: { name: 'Facebook', icon: 'fa-brands fa-facebook', color: '#1877f2', av: { rec: '320 × 320', min: 176 }, cover: { rec: '851 × 315 (mín. 400 × 150)', min: 400, ratio: { pc: 820 / 312, tab: 820 / 312, mob: 640 / 360 } },
      tips: ['En el celular la portada se recorta a los costados: lo importante, en el centro.', 'La foto se ve redonda: dejá aire alrededor de la cara.'] },
    instagram: { name: 'Instagram', icon: 'fa-brands fa-instagram', color: '#e1306c', av: { rec: '320 × 320', min: 110 }, cover: null,
      tips: ['Instagram no tiene portada: los destacados y las primeras publicaciones hacen ese papel.', 'La foto se ve muy chica en el celular (77 px): simple y con contraste.'] },
    x: { name: 'X', icon: 'fa-brands fa-x-twitter', color: '#a1a1aa', av: { rec: '400 × 400', min: 200 }, cover: { rec: '1500 × 500', min: 1000, ratio: { pc: 3, tab: 3, mob: 3 } },
      tips: ['La foto tapa la esquina de abajo a la izquierda de la portada.', 'En el celular la portada se ve más chica: nada de texto pequeño.'] },
    linkedin: { name: 'LinkedIn', icon: 'fa-brands fa-linkedin', color: '#0a66c2', av: { rec: '400 × 400', min: 200 }, cover: { rec: '1584 × 396', min: 1000, ratio: { pc: 4, tab: 4, mob: 3 } },
      tips: ['La foto tapa la parte izquierda de la portada.', 'En el celular la portada se recorta más alta (3:1).'] },
    youtube: { name: 'YouTube', icon: 'fa-brands fa-youtube', color: '#ff0033', av: { rec: '800 × 800', min: 98 }, cover: { rec: '2560 × 1440 (zona segura central 1546 × 423)', min: 2048, ratio: { pc: 2560 / 423, tab: 1855 / 423, mob: 1546 / 423 } },
      tips: ['De la portada se ve solo una franja: la zona segura central (1546 × 423) aparece en todos los equipos.', 'Logos y textos, dentro de la zona segura.'] },
    tiktok: { name: 'TikTok', icon: 'fa-brands fa-tiktok', color: '#25f4ee', av: { rec: '200 × 200 o más', min: 100 }, cover: null,
      tips: ['TikTok no tiene portada: la foto y la descripción son todo.', 'En computadora la foto se ve grande a la izquierda.'] },
  };
  const DEV = { pc: [1180, 'Computadora'], tab: [820, 'Tablet'], mob: [390, 'Celular'] };
  const def = () => ({ net: 'facebook', dev: 'pc', theme: 'light', safe: false, all: false, avatar: null, cover: null,
    name: 'Tu nombre', user: 'tu_usuario', bio: 'Una línea que cuente quién sos o qué hacés.', followers: '12,4 mil', following: '312', posts: '248', verified: false, category: 'Creador de contenido', link: 'tusitio.com' });
  let S = def();
  try { const o = JSON.parse(localStorage.getItem(KEY) || 'null'); if (o) S = Object.assign(def(), o); } catch (e) {}
  const persist = () => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { try { const c = Object.assign({}, S); if (c.avatar && String(c.avatar.src).startsWith('data:')) c.avatar = null; if (c.cover && String(c.cover.src).startsWith('data:')) c.cover = null; localStorage.setItem(KEY, JSON.stringify(c)); toast('Las imágenes son grandes para guardarlas en el navegador: se van a borrar al recargar.', true); } catch (e2) {} } };
  const toast = (m, bad) => { const t = $('#sim-toast'); t.textContent = m; t.classList.toggle('bad', !!bad); t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => t.hidden = true, bad ? 5000 : 2600); };

  // ═══════════ Imágenes ═══════════
  /** Imagen elegida → {src, w, h, x, y, z}. Las grandes se achican (para que entren en el navegador). */
  function loadFile(file, kind) {
    return new Promise((res, rej) => {
      if (!file.type.startsWith('image/')) return rej(new Error('Tiene que ser una imagen.'));
      const r = new FileReader();
      r.onload = () => { const im = new Image(); im.onload = () => {
        const max = kind === 'cover' ? 2560 : 800, k = Math.min(1, max / Math.max(im.naturalWidth, im.naturalHeight));
        const c = document.createElement('canvas'); c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k);
        c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
        res({ src: c.toDataURL(file.type === 'image/png' ? 'image/png' : 'image/jpeg', .86), w: im.naturalWidth, h: im.naturalHeight, x: 50, y: 50, z: 1, name: file.name }); };
        im.onerror = () => rej(new Error('No se pudo leer la imagen.')); im.src = r.result; };
      r.readAsDataURL(file);
    });
  }
  function pick(kind) {
    const f = $('#sim-file');
    f.onchange = async () => { const file = f.files[0]; f.value = ''; if (!file) return; try { S[kind] = await loadFile(file, kind); persist(); draw(); panel(); toast(kind === 'cover' ? 'Portada lista: arrastrala para acomodarla.' : 'Foto lista: arrastrala para acomodarla.'); } catch (e) { toast(e.message, true); } };
    f.click();
  }
  async function fromStudio(kind) {
    let items = [];
    try { const r = await fetch('../api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-SS': '1' }, credentials: 'same-origin', body: JSON.stringify({ action: 'pf_list' }) }); const j = await r.json(); if (!r.ok || j.error) throw new Error(j.error || 'No se pudo cargar.'); items = j.items.filter(i => i.thumb); }
    catch (e) { return toast(e.message, true); }
    const d = document.createElement('dialog'); d.className = 'pbe-dlg';
    d.innerHTML = `<h2><i class="fa-solid fa-circle-user"></i> Tus diseños de Profile Studio</h2>${items.length ? `<div class="sim-pick">${items.map(i => `<button type="button" data-id="${i.id}"><img src="${esc(i.thumb)}" alt=""><span>${esc(i.name)}</span></button>`).join('')}</div>` : '<p>Todavía no tenés diseños guardados con miniatura.</p>'}<p class="pbe-hint">Se usa la miniatura guardada del diseño. Para la mejor calidad, exportalo en Profile Studio y elegí el archivo.</p><div class="pbe-dlg-f"><button type="button" class="pbe-btn" data-close>Cerrar</button></div>`;
    document.body.append(d); d.showModal();
    d.querySelector('[data-close]').addEventListener('click', () => d.close());
    d.addEventListener('close', () => d.remove());
    d.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', () => { const it = items.find(i => i.id === +b.dataset.id); const im = new Image();
      im.onload = () => { S[kind] = { src: it.thumb, w: im.naturalWidth, h: im.naturalHeight, x: 50, y: 50, z: 1, name: it.name }; persist(); draw(); panel(); d.close(); toast('Diseño elegido.'); }; im.src = it.thumb; }));
  }

  // ═══════════ Panel ═══════════
  function panel() {
    const n = NETS[S.net];
    const imgBox = (kind, label, spec) => { const v = S[kind];
      const warn = v && spec ? (Math.min(v.w, v.h) < spec.min && kind === 'avatar' ? `<p class="sim-warn"><i class="fa-solid fa-triangle-exclamation"></i> Es chica (${v.w} × ${v.h}): en ${n.name} se puede ver borrosa. Recomendado: ${spec.rec}.</p>` : (kind === 'cover' && v.w < spec.min ? `<p class="sim-warn"><i class="fa-solid fa-triangle-exclamation"></i> Mide ${v.w} px de ancho: recomendado ${spec.rec}.</p>` : `<p class="sim-ok"><i class="fa-solid fa-check"></i> ${v.w} × ${v.h} px · recomendado ${spec.rec}</p>`)) : (spec ? `<p class="pbe-hint">Recomendado: ${spec.rec}</p>` : '');
      return `<section class="sim-sec"><h3>${label}</h3><div class="sim-img${kind === 'avatar' ? ' round' : ''}">${v ? `<img src="${esc(v.src)}" alt="">` : `<i class="fa-regular fa-image"></i>`}</div>
        <div class="sim-btns"><button type="button" class="pbe-btn" data-pick="${kind}"><i class="fa-solid fa-upload"></i> Del dispositivo</button><button type="button" class="pbe-btn" data-studio="${kind}"><i class="fa-solid fa-circle-user"></i> De Profile Studio</button>${v ? `<button type="button" class="pbe-icon" data-clear="${kind}" title="Quitar" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button>` : ''}</div>
        ${v ? `<div class="pbe-f"><label>Acercar</label><div class="pbe-numrow"><input type="range" min="1" max="3" step="0.01" value="${v.z}" data-zoom="${kind}"><button type="button" class="pbe-btn pbe-ghost" data-center="${kind}">Centrar</button></div></div>` : ''}${warn}</section>`; };
    const f = (k, l, ph = '') => `<div class="pbe-f"><label>${l}</label><input type="text" data-f="${k}" value="${esc(S[k])}" placeholder="${esc(ph)}"></div>`;
    $('#sim-left').innerHTML = `<div class="pbe-pane">${imgBox('avatar', 'Foto de perfil', n.av)}${n.cover ? imgBox('cover', 'Portada', n.cover) : `<section class="sim-sec"><h3>Portada</h3><p class="pbe-hint">${esc(n.name)} no usa portada.</p></section>`}
      <section class="sim-sec"><h3>Datos del perfil</h3>${f('name', 'Nombre')}${f('user', 'Usuario', 'sin @')}<div class="pbe-f"><label>Descripción</label><textarea data-f="bio" rows="3">${esc(S.bio)}</textarea></div>${f('category', 'Categoría o titular')}${f('link', 'Enlace')}
        <div class="sim-3">${f('followers', 'Seguidores')}${f('following', 'Seguidos')}${f('posts', 'Publicaciones')}</div><div class="pbe-f pbe-f-bool"><input type="checkbox" class="pbe-switch" id="sim-ver"${S.verified ? ' checked' : ''}><label for="sim-ver">Cuenta verificada</label></div></section>
      <section class="sim-sec"><h3><i class="${n.icon}"></i> ${esc(n.name)}: a tener en cuenta</h3><ul class="sim-tips">${n.tips.map(t => `<li>${esc(t)}</li>`).join('')}</ul><p class="pbe-hint">Es una maqueta aproximada: cada red cambia su diseño de vez en cuando.</p></section></div>`;
    $$('[data-pick]').forEach(b => b.addEventListener('click', () => pick(b.dataset.pick)));
    $$('[data-studio]').forEach(b => b.addEventListener('click', () => fromStudio(b.dataset.studio)));
    $$('[data-clear]').forEach(b => b.addEventListener('click', () => { S[b.dataset.clear] = null; persist(); draw(); panel(); }));
    $$('[data-zoom]').forEach(r => r.addEventListener('input', () => { S[r.dataset.zoom].z = +r.value; persist(); draw(); }));
    $$('[data-center]').forEach(b => b.addEventListener('click', () => { const v = S[b.dataset.center]; v.x = 50; v.y = 50; v.z = 1; persist(); draw(); panel(); }));
    $$('[data-f]').forEach(i => i.addEventListener('input', () => { S[i.dataset.f] = i.value; persist(); draw(); }));
    $('#sim-ver').addEventListener('change', e => { S.verified = e.target.checked; persist(); draw(); });
  }

  // ═══════════ Maquetas ═══════════
  const img = (kind, cls) => { const v = S[kind];
    if (!v) return `<div class="sim-ph ${cls}" data-drop="${kind}">${kind === 'cover' ? '<i class="fa-regular fa-image"></i><span>Portada</span>' : '<i class="fa-solid fa-user"></i>'}</div>`;
    return `<div class="sim-ph ${cls} has" data-drag="${kind}"><img src="${esc(v.src)}" alt="" draggable="false" style="object-position:${v.x}% ${v.y}%;transform:scale(${v.z});transform-origin:${v.x}% ${v.y}%"></div>`; };
  const ver = () => S.verified ? ' <i class="fa-solid fa-circle-check sim-verified" title="Verificada"></i>' : '';
  const grid = (n, cls = '') => `<div class="sim-grid ${cls}">${Array.from({ length: n }, (_, i) => `<i style="--i:${i}"></i>`).join('')}</div>`;
  const safeBox = (net, dev) => {
    if (!S.safe) return '';
    const z = { facebook: dev === 'mob' ? [] : [[11, 0, 78, 100, 'Se ve en el celular']], youtube: dev === 'mob' ? [] : [[(1 - 1546 / (dev === 'pc' ? 2560 : 1855)) / 2 * 100, 0, 1546 / (dev === 'pc' ? 2560 : 1855) * 100, 100, 'Zona segura (se ve en todos)']],
      x: [[3, 55, 20, 45, 'Lo tapa la foto']], linkedin: [[2, 45, dev === 'mob' ? 34 : 18, 55, 'Lo tapa la foto']] }[net] || [];
    return z.map(([l, t, w, h, lab]) => `<span class="sim-safe" style="left:${l}%;top:${t}%;width:${w}%;height:${h}%"><b>${esc(lab)}</b></span>`).join('');
  };
  function mock(net, dev) {
    const n = NETS[net], cr = n.cover ? n.cover.ratio[dev] : 0, u = esc(S.user.replace(/^@/, '')), nm = esc(S.name);
    const cover = n.cover ? `<div class="sim-cover" style="aspect-ratio:${cr}">${img('cover', 'cover')}${safeBox(net, dev)}</div>` : '';
    switch (net) {
      case 'facebook': return `<div class="m-fb"><div class="m-bar"><i class="${n.icon}"></i><span class="m-search"></span></div>${cover}
        <div class="m-fb-h">${img('avatar', 'av')}<div class="m-fb-n"><h1>${nm}${ver()}</h1><p><b>${esc(S.followers)}</b> seguidores · <b>${esc(S.following)}</b> seguidos</p></div><div class="m-btns"><span class="m-btn pri">Seguir</span><span class="m-btn">Mensaje</span></div></div>
        <nav class="m-tabs"><b>Publicaciones</b><span>Información</span><span>Fotos</span><span>Videos</span></nav><div class="m-fb-b"><div class="m-card"><h3>Detalles</h3><p>${esc(S.bio)}</p><p>${esc(S.category)}</p><p class="m-link">${esc(S.link)}</p></div><div class="m-card m-post"><div class="m-post-h">${img('avatar', 'av-s')}<b>${nm}</b></div><div class="m-post-img"></div></div></div></div>`;
      case 'instagram': return `<div class="m-ig"><div class="m-bar"><b class="m-logo">Instagram</b></div><div class="m-ig-h">${img('avatar', 'av')}<div class="m-ig-i"><p class="m-ig-u">${u}${ver()} <span class="m-btn pri">Seguir</span><span class="m-btn">Mensaje</span></p>
        <p class="m-ig-s"><span><b>${esc(S.posts)}</b> publicaciones</span><span><b>${esc(S.followers)}</b> seguidores</span><span><b>${esc(S.following)}</b> seguidos</span></p><p><b>${nm}</b></p><p class="m-muted">${esc(S.category)}</p><p>${esc(S.bio)}</p><p class="m-link">${esc(S.link)}</p></div></div>
        <div class="m-ig-hl">${['Novedades', 'Viajes', 'Equipo', 'FAQ', 'Prensa'].map(h => `<span><i></i>${h}</span>`).join('')}</div><nav class="m-tabs"><b><i class="fa-solid fa-table-cells"></i> Publicaciones</b><span><i class="fa-solid fa-film"></i> Reels</span><span><i class="fa-regular fa-id-badge"></i> Etiquetadas</span></nav>${grid(9, 'g3')}</div>`;
      case 'x': return `<div class="m-x"><div class="m-x-top"><i class="fa-solid fa-arrow-left"></i><div><b>${nm}${ver()}</b><small>${esc(S.posts)} posts</small></div></div>${cover}<div class="m-x-h">${img('avatar', 'av')}<span class="m-btn pri">Seguir</span></div>
        <div class="m-x-i"><h1>${nm}${ver()}</h1><p class="m-muted">@${u}</p><p>${esc(S.bio)}</p><p class="m-muted"><i class="fa-solid fa-link"></i> <span class="m-link">${esc(S.link)}</span></p><p><b>${esc(S.following)}</b> <span class="m-muted">Siguiendo</span> &nbsp; <b>${esc(S.followers)}</b> <span class="m-muted">Seguidores</span></p></div>
        <nav class="m-tabs"><b>Posts</b><span>Respuestas</span><span>Multimedia</span><span>Me gusta</span></nav><div class="m-x-post">${img('avatar', 'av-s')}<div><p><b>${nm}</b> <span class="m-muted">@${u} · 2 h</span></p><p class="m-lines"><i></i><i></i></p></div></div></div>`;
      case 'linkedin': return `<div class="m-li"><div class="m-bar"><i class="${n.icon}"></i><span class="m-search"></span></div><div class="m-card m-li-c">${cover}<div class="m-li-h">${img('avatar', 'av')}</div>
        <div class="m-li-i"><h1>${nm}${ver()}</h1><p>${esc(S.category)}</p><p class="m-muted">${esc(S.bio)}</p><p class="m-link">${esc(S.followers)} seguidores · ${esc(S.following)} contactos</p><div class="m-btns"><span class="m-btn pri">Conectar</span><span class="m-btn">Mensaje</span><span class="m-btn">Más</span></div></div></div>
        <div class="m-card"><h3>Acerca de</h3><p class="m-lines"><i></i><i></i><i></i></p></div></div>`;
      case 'youtube': return `<div class="m-yt"><div class="m-bar"><i class="${n.icon}"></i><span class="m-search"></span></div>${cover}<div class="m-yt-h">${img('avatar', 'av')}<div class="m-yt-i"><h1>${nm}${ver()}</h1><p class="m-muted">@${u} · ${esc(S.followers)} suscriptores · ${esc(S.posts)} videos</p><p class="m-muted">${esc(S.bio)}</p><span class="m-btn pri">Suscribirme</span></div></div>
        <nav class="m-tabs"><b>Inicio</b><span>Videos</span><span>Shorts</span><span>Listas</span></nav>${grid(dev === 'mob' ? 2 : 4, 'g-yt')}</div>`;
      case 'tiktok': return `<div class="m-tt"><div class="m-tt-h">${img('avatar', 'av')}<div class="m-tt-i"><h1>${u}${ver()}</h1><p>${nm}</p><div class="m-btns"><span class="m-btn pri">Seguir</span><span class="m-btn">Mensaje</span></div>
        <p class="m-ig-s"><span><b>${esc(S.following)}</b> Siguiendo</span><span><b>${esc(S.followers)}</b> Seguidores</span><span><b>${esc(S.posts)}</b> Me gusta</span></p><p>${esc(S.bio)}</p><p class="m-link">${esc(S.link)}</p></div></div>
        <nav class="m-tabs"><b>Videos</b><span>Me gusta</span></nav>${grid(dev === 'pc' ? 8 : 6, 'g-tt')}</div>`;
    }
    return '';
  }
  function draw() {
    const dev = S.dev, w = DEV[dev][0];
    $$('#sim-nets button').forEach(b => b.classList.toggle('on', !S.all && b.dataset.net === S.net) || (b.dataset.net === 'all' && b.classList.toggle('on', S.all)));
    $$('#sim-dev button').forEach(b => b.classList.toggle('on', b.dataset.dev === dev));
    $('#sim-safe').setAttribute('aria-pressed', S.safe ? 'true' : 'false'); $('#sim-safe').classList.toggle('on', S.safe);
    const box = $('#sim-device');
    const one = net => `<div class="sim-screen sim-${dev} sim-th-${S.theme}" style="width:${w}px" data-net="${net}">${dev !== 'pc' ? '<div class="sim-notch"></div>' : '<div class="sim-chrome"><i></i><i></i><i></i><span></span></div>'}<div class="sim-scroll">${mock(net, dev)}</div></div>`;
    box.className = 'sim-device' + (S.all ? ' sim-all' : '');
    box.innerHTML = S.all ? Object.keys(NETS).map(k => `<figure><figcaption><i class="${NETS[k].icon}" style="color:${NETS[k].color}"></i> ${esc(NETS[k].name)}</figcaption>${one(k)}</figure>`).join('') : one(S.net);
    $('#sim-cap').innerHTML = `<i class="${S.all ? 'fa-solid fa-layer-group' : NETS[S.net].icon}"></i> ${S.all ? 'Todas las redes' : esc(NETS[S.net].name)} · ${DEV[dev][1]} (${w} px) · arrastrá la foto o la portada para acomodarlas`;
    fit(); bindDrag();
  }
  function fit() {
    const st = $('#sim-stage'), fitEl = $('#sim-fit'), dev = $('#sim-device');
    dev.style.transform = 'none';
    const W = st.clientWidth - 48, H = st.clientHeight - 80, dw = dev.scrollWidth, dh = dev.scrollHeight;
    const k = Math.min(1, W / dw, S.all ? 1 : Math.max(.35, H / Math.min(dh, H * 3)));
    dev.style.transform = `scale(${k})`; fitEl.style.width = dw * k + 'px'; fitEl.style.height = dh * k + 'px';
  }
  addEventListener('resize', fit);
  /** Arrastrar la imagen dentro de su recuadro mueve el punto de foco (object-position). */
  function bindDrag() {
    $$('#sim-device [data-drag]').forEach(el => el.addEventListener('pointerdown', e => {
      e.preventDefault(); const kind = el.dataset.drag, v = S[kind], r = el.getBoundingClientRect(), x0 = e.clientX, y0 = e.clientY, vx = v.x, vy = v.y;
      el.setPointerCapture(e.pointerId); el.classList.add('drag');
      const mv = ev => { v.x = Math.max(0, Math.min(100, vx - (ev.clientX - x0) / r.width * 100 / v.z)); v.y = Math.max(0, Math.min(100, vy - (ev.clientY - y0) / r.height * 100 / v.z));
        $$(`#sim-device [data-drag="${kind}"] img`).forEach(i => { i.style.objectPosition = `${v.x}% ${v.y}%`; i.style.transformOrigin = `${v.x}% ${v.y}%`; }); };
      const up = () => { el.removeEventListener('pointermove', mv); el.removeEventListener('pointerup', up); el.classList.remove('drag'); persist(); };
      el.addEventListener('pointermove', mv); el.addEventListener('pointerup', up);
    }));
    $$('#sim-device [data-drop]').forEach(el => el.addEventListener('click', () => pick(el.dataset.drop)));
  }
  // Soltar una imagen sobre el escenario: si cae en la portada, portada; si no, foto
  $('#sim-stage').addEventListener('dragover', e => { if (e.dataTransfer.types.includes('Files')) e.preventDefault(); });
  $('#sim-stage').addEventListener('drop', async e => { const f = e.dataTransfer.files[0]; if (!f) return; e.preventDefault();
    const kind = e.target.closest('.sim-cover') && NETS[S.net].cover ? 'cover' : 'avatar'; try { S[kind] = await loadFile(f, kind); persist(); draw(); panel(); } catch (er) { toast(er.message, true); } });

  // ═══════════ Barra ═══════════
  $('#sim-nets').innerHTML = Object.entries(NETS).map(([k, n]) => `<button type="button" data-net="${k}" title="${esc(n.name)}"><i class="${n.icon}"></i><span>${esc(n.name)}</span></button>`).join('') + '<button type="button" data-net="all" title="Todas las redes"><i class="fa-solid fa-layer-group"></i><span>Todas</span></button>';
  $$('#sim-nets button').forEach(b => b.addEventListener('click', () => { if (b.dataset.net === 'all') S.all = !S.all; else { S.all = false; S.net = b.dataset.net; } persist(); draw(); panel(); }));
  $$('#sim-dev button').forEach(b => b.addEventListener('click', () => { S.dev = b.dataset.dev; persist(); draw(); }));
  $('#sim-theme').addEventListener('click', () => { S.theme = S.theme === 'light' ? 'dark' : 'light'; persist(); draw(); toast(S.theme === 'dark' ? 'Modo oscuro' : 'Modo claro'); });
  $('#sim-safe').addEventListener('click', () => { S.safe = !S.safe; persist(); draw(); });
  addEventListener('keydown', e => { if (/^(INPUT|TEXTAREA)$/.test(e.target.tagName)) return; if (e.key === '1') $('#sim-dev [data-dev="pc"]').click(); if (e.key === '2') $('#sim-dev [data-dev="tab"]').click(); if (e.key === '3') $('#sim-dev [data-dev="mob"]').click(); });
  panel(); draw();
})();
