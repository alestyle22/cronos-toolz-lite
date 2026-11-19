/* assets/g7-tools.js — Juice de las herramientas (Landing Gen, Mailing Maker, placas, Links, WebP, SVG, Renombrador…).
 * Capa común que no toca el código de cada herramienta: escucha lo que pasa en la página y responde con movimiento,
 * sonido y festejos. Usa el motor de g7-juice.js (G7Juice._fx). El movimiento respeta html.g7-move y el sonido la
 * preferencia "Sonidos de la interfaz". Doc: toolz/MOVIMIENTO.md › Herramientas.
 *
 *   Archivos      arrastrar → las zonas de carga se iluminan; soltar o elegir → rebote, sonido y fichas de archivo
 *   Descargas     cualquier descarga (enlace con download, blob:, data:) → ficha que vuela a la esquina + festejo
 *   Copiar        portapapeles → "¡Copiado!" donde está el mouse, chispas y el botón con tilde
 *   Deslizadores  globito con el valor mientras se arrastra y un tic que sube de tono con el valor
 *   Colores       el selector salta y suena al elegir
 *   Generar       botones de generar/convertir/exportar con brillo y pulso mientras trabaja
 *   Resultados    lo nuevo que aparece en listas y grillas entra con rebote; imágenes nuevas con destello
 *   Progreso      una barra que llega al 100 % suena y brilla
 *   Deshacer      Ctrl+Z / Ctrl+Y con su sonido
 */
(function () {
  'use strict';
  if (window.__g7tools) return; window.__g7tools = true;
  const root = document.documentElement;
  const J = () => window.G7Juice || null;
  const fx = () => (J() && J()._fx) || null;
  const moving = () => { const j = J(); return j ? j.motion : root.classList.contains('g7-move'); };
  const play = (n, gap, force) => { const f = fx(); if (f) f.play(n, gap, force); };
  const SPRING = 'cubic-bezier(.34,1.56,.64,1)';

  // Página de herramienta: html.g7t y html.g7t-<carpeta o archivo> para estilos propios de cada una
  const slug = (location.pathname.replace(/^\/toolz\/?/, '').split('/')[0] || 'inicio').replace(/\.(php|html)$/, '').replace(/[^a-z0-9_-]/gi, '').toLowerCase();
  const TOOLS = /^(landing-gen|mailingmaker|popup-gen|linkgen|linkgenerator|placasdeportivas|placaspaid|webpmaker|webp_converter|jpg_converter|svg_converter|recolorearsvg|renombrador|imghost|stock|pages|planify|sport_placas)$/;
  if (TOOLS.test(slug)) root.classList.add('g7t', 'g7t-' + slug);

  // Última posición del puntero (para que los avisos salgan donde está la mano)
  let px = innerWidth / 2, py = innerHeight / 2, lastTarget = null, lastDownAt = 0;
  addEventListener('pointerdown', e => { px = e.clientX; py = e.clientY; lastTarget = e.target; lastDownAt = performance.now(); }, true);
  addEventListener('pointermove', e => { px = e.clientX; py = e.clientY; }, { passive: true, capture: true });

  /* ── piezas visuales propias ───────────────────────────── */
  function sparks(x, y, n = 10, color){
    if (!moving()) return;
    const c = color || getComputedStyle(root).getPropertyValue('--g7-accent').trim() || '#ff6600';
    for (let i = 0; i < n; i++){
      const s = document.createElement('i'); s.className = 'g7t-spark'; s.style.left = x + 'px'; s.style.top = y + 'px'; s.style.background = i % 3 ? c : '#fff';
      document.body.appendChild(s);
      const a = (i / n) * Math.PI * 2 + Math.random() * .6, d = 26 + Math.random() * 34;
      s.animate([{ transform: 'translate(-50%,-50%) scale(1)', opacity: 1 }, { transform: `translate(calc(-50% + ${Math.cos(a) * d}px), calc(-50% + ${Math.sin(a) * d}px)) scale(.2)`, opacity: 0 }],
        { duration: 520 + Math.random() * 200, easing: 'cubic-bezier(.2,.8,.3,1)' }).onfinish = () => s.remove();
      setTimeout(() => s.remove(), 900);
    }
  }
  function toast(html, x, y, cls = ''){
    const d = document.createElement('div'); d.className = 'g7t-toast ' + cls; d.innerHTML = html; document.body.appendChild(d);
    const w = d.offsetWidth, h = d.offsetHeight;
    const L = Math.max(8, Math.min(innerWidth - w - 8, x - w / 2)), T = Math.max(8, Math.min(innerHeight - h - 8, y - h - 18));
    d.style.left = L + 'px'; d.style.top = T + 'px';
    if (moving()) d.animate([{ opacity: 0, transform: 'translateY(10px) scale(.6)' }, { opacity: 1, transform: 'translateY(-4px) scale(1.06)', offset: .55 }, { opacity: 1, transform: 'none' }], { duration: 420, easing: 'cubic-bezier(.2,.8,.2,1)' });
    setTimeout(() => { if (moving()) d.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-14px) scale(.9)' }], { duration: 260, fill: 'forwards' }).onfinish = () => d.remove(); else d.remove(); }, 1300);
    setTimeout(() => d.remove(), 2200);
    return d;
  }
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const bump = (el, k = 1.06) => { if (el && moving() && el.animate) el.animate([{ scale: '1' }, { scale: String(k) }, { scale: '.98' }, { scale: '1' }], { duration: 380, easing: 'ease-out' }); };
  const fileIcon = n => /\.(png|jpe?g|webp|gif|avif|svg)$/i.test(n) ? 'fa-image' : /\.zip$/i.test(n) ? 'fa-file-zipper' : /\.html?$/i.test(n) ? 'fa-file-code' : /\.(mp4|webm|mov)$/i.test(n) ? 'fa-film' : /\.pdf$/i.test(n) ? 'fa-file-pdf' : /\.(csv|xlsx?)$/i.test(n) ? 'fa-file-csv' : 'fa-file-arrow-down';

  /* ── Descargas ─────────────────────────────────────────── */
  let dlBatch = [], dlTimer = 0, lastBig = 0;
  // Flash de cámara sobre el lienzo más grande (placas, SVG): la pieza "se saca"
  let flashAt = 0;
  function shutter(){
    const now = performance.now(); if (now - flashAt < 900) return; flashAt = now;
    const cv = [...document.querySelectorAll('canvas, svg.stage, [class*="stage" i] svg, #stage, .stage')].filter(c => c.offsetWidth > 160 && c.offsetHeight > 120)
      .sort((a, b) => b.offsetWidth * b.offsetHeight - a.offsetWidth * a.offsetHeight)[0];
    const f = fx(), j = J();
    if (f && j && j.soundOn) try { f.hiss(.05, { f: 5200, q: 2.5, vol: .35 }); f.hiss(.09, { f: 2200, q: 1.4, vol: .22, at: .06 }); f.tone(1900, .04, { type: 'square', vol: .05, at: .02 }); } catch (e) {}
    if (!cv || !moving()) return;
    const r = cv.getBoundingClientRect(), d = document.createElement('div'); d.className = 'g7t-shot';
    Object.assign(d.style, { left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' }); document.body.appendChild(d);
    d.animate([{ opacity: .95 }, { opacity: 0 }], { duration: 520, easing: 'ease-out' }).onfinish = () => d.remove(); setTimeout(() => d.remove(), 800);
    cv.animate([{ scale: '1' }, { scale: '.97' }, { scale: '1.01' }, { scale: '1' }], { duration: 380, easing: 'ease-out' });
  }
  function onDownload(name){
    if (/\.(png|jpe?g|webp|gif|svg)$/i.test(name || '') || /^(placasdeportivas|placaspaid|svg_converter|recolorearsvg)$/.test(slug)) shutter();
    dlBatch.push(name || 'archivo');
    clearTimeout(dlTimer);
    dlTimer = setTimeout(() => {
      const list = dlBatch; dlBatch = [];
      const n = list.length, label = n > 1 ? `${n} archivos descargados` : (list[0] || 'Descargado');
      // Ficha que vuela desde la mano hasta la esquina de descargas del navegador (arriba a la derecha)
      if (moving()){
        const c = document.createElement('div'); c.className = 'g7t-fly'; c.innerHTML = `<i class="fa-solid ${n > 1 ? 'fa-layer-group' : fileIcon(list[0])}"></i>${n > 1 ? `<b>${n}</b>` : ''}`; document.body.appendChild(c);
        const x0 = px, y0 = py, x1 = innerWidth - 60, y1 = 18;
        c.animate([{ transform: `translate(${x0}px,${y0}px) translate(-50%,-50%) scale(.6)`, opacity: 0 },
          { transform: `translate(${x0}px,${y0 - 40}px) translate(-50%,-50%) scale(1.15)`, opacity: 1, offset: .2 },
          { transform: `translate(${(x0 + x1) / 2}px,${Math.min(y0, y1) - 30}px) translate(-50%,-50%) scale(1) rotate(-8deg)`, opacity: 1, offset: .6 },
          { transform: `translate(${x1}px,${y1}px) translate(-50%,-50%) scale(.4)`, opacity: 0 }], { duration: 900, easing: 'cubic-bezier(.45,.05,.35,1)' }).onfinish = () => c.remove();
        setTimeout(() => c.remove(), 1200);
      }
      toast(`<i class="fa-solid fa-circle-down"></i><span>${esc(label.length > 40 ? label.slice(0, 37) + '…' : label)}</span>`, px, py, 'ok');
      const now = performance.now();
      if (n >= 3 || now - lastBig > 20000){ lastBig = now; const j = J(); if (j) j.celebrate(n >= 3 ? 'medium' : 'subtle', n >= 3 ? `¡${n} listos!` : '', 'fa-download'); }
      else play('chime', 0);
    }, 260);
  }
  const isDl = a => a && (a.hasAttribute('download') || /^(blob|data):/i.test(a.getAttribute('href') || ''));
  const nameOf = a => a.getAttribute('download') || decodeURIComponent((a.getAttribute('href') || '').split(/[?#]/)[0].split('/').pop() || '').slice(0, 80);
  const seen = new WeakSet();
  const origClick = HTMLAnchorElement.prototype.click;
  HTMLAnchorElement.prototype.click = function(){ try { if (isDl(this) && !seen.has(this)){ seen.add(this); setTimeout(() => seen.delete(this), 800); onDownload(nameOf(this)); } } catch (e) {} return origClick.apply(this, arguments); };
  document.addEventListener('click', e => { const a = e.target.closest && e.target.closest('a'); if (a && isDl(a) && !seen.has(a)){ seen.add(a); setTimeout(() => seen.delete(a), 800); onDownload(nameOf(a)); } }, true);

  /* ── Copiar al portapapeles ────────────────────────────── */
  function onCopy(text){
    const btn = lastTarget && lastTarget.closest && lastTarget.closest('button, a, [role="button"], .btn');
    const preview = typeof text === 'string' ? text.trim().replace(/\s+/g, ' ') : '';
    toast(`<i class="fa-solid fa-check"></i><span>¡Copiado!</span>${preview ? `<small>${esc(preview.length > 34 ? preview.slice(0, 31) + '…' : preview)}</small>` : ''}`, px, py, 'ok');
    sparks(px, py, 12);
    play('pluck', 0);
    if (btn && moving()){
      bump(btn, 1.08);
      btn.classList.add('g7t-copied'); setTimeout(() => btn.classList.remove('g7t-copied'), 1100);
    }
  }
  try {
    const cb = navigator.clipboard;
    if (cb && cb.writeText){ const ow = cb.writeText.bind(cb); cb.writeText = function(t){ const p = ow(t); Promise.resolve(p).then(() => onCopy(t), () => {}); return p; }; }
    if (cb && cb.write){ const o2 = cb.write.bind(cb); cb.write = function(d){ const p = o2(d); Promise.resolve(p).then(() => onCopy(''), () => {}); return p; }; }
  } catch (e) {}
  const oe = document.execCommand && document.execCommand.bind(document);
  if (oe) document.execCommand = function(cmd){ const r = oe.apply(document, arguments); try { if (r && /^copy$/i.test(cmd)) onCopy(String(getSelection() || '')); } catch (e) {} return r; };

  /* ── Archivos: arrastrar, soltar, elegir ───────────────── */
  const ZONE = '[class*="drop" i]:not(.dropdown):not([class*="dropdown" i]), [id*="drop" i], [class*="upload" i], [class*="dz" i], label:has(input[type="file"])';
  let dragDepth = 0, hot = null;
  const hasFiles = e => e.dataTransfer && [...(e.dataTransfer.types || [])].includes('Files');
  const zones = () => [...document.querySelectorAll(ZONE)].filter(z => z.offsetWidth > 60 && z.offsetHeight > 30 && !z.closest('.g7-topbar'));
  function dragOn(){ root.classList.add('g7t-dragging'); zones().forEach(z => z.classList.add('g7t-zone')); }
  function dragOff(){ root.classList.remove('g7t-dragging'); document.querySelectorAll('.g7t-zone, .g7t-hot').forEach(z => z.classList.remove('g7t-zone', 'g7t-hot')); hot = null; dragDepth = 0; }
  addEventListener('dragenter', e => { if (!hasFiles(e)) return; if (dragDepth++ === 0) dragOn();
    const z = e.target.closest && e.target.closest('.g7t-zone');
    if (z !== hot){ if (hot) hot.classList.remove('g7t-hot'); hot = z; if (z){ z.classList.add('g7t-hot'); play('lift', 150); } } }, true);
  addEventListener('dragleave', e => { if (!hasFiles(e)) return; if (--dragDepth <= 0) dragOff(); }, true);
  addEventListener('drop', e => {
    const files = e.dataTransfer && e.dataTransfer.files ? [...e.dataTransfer.files] : [];
    const z = hot || (e.target.closest && e.target.closest(ZONE)); dragOff();
    if (files.length) received(files, z, e.clientX, e.clientY);
  }, true);
  addEventListener('dragend', dragOff, true);
  document.addEventListener('change', e => {
    const inp = e.target; if (!inp || inp.type !== 'file' || !inp.files || !inp.files.length) return;
    const z = inp.closest(ZONE) || (inp.id && document.querySelector(`label[for="${CSS.escape(inp.id)}"]`)) || inp.parentElement;
    const r = (z && z.getBoundingClientRect && z.offsetWidth) ? z.getBoundingClientRect() : null;
    received([...inp.files], z, r ? r.left + r.width / 2 : px, r ? r.top + r.height / 2 : py);
  }, true);
  function received(files, zone, x, y){
    play('drop', 0);
    setTimeout(() => play('pop', 0), 140);
    if (zone && moving()){ zone.classList.add('g7t-got'); setTimeout(() => zone.classList.remove('g7t-got'), 700); bump(zone, 1.03); }
    sparks(x, y, 14);
    if (moving()) files.slice(0, 6).forEach((f, i) => {
      const c = document.createElement('div'); c.className = 'g7t-filechip'; c.innerHTML = `<i class="fa-solid ${fileIcon(f.name)}"></i><span>${esc(f.name.length > 22 ? f.name.slice(0, 19) + '…' : f.name)}</span>`;
      document.body.appendChild(c);
      const dx = (i - (Math.min(files.length, 6) - 1) / 2) * 34;
      c.animate([{ transform: `translate(${x}px,${y}px) translate(-50%,-50%) scale(.3)`, opacity: 0 },
        { transform: `translate(${x + dx}px,${y - 70 - i * 6}px) translate(-50%,-50%) scale(1.05) rotate(${dx / 10}deg)`, opacity: 1, offset: .4 },
        { transform: `translate(${x + dx * .4}px,${y - 20}px) translate(-50%,-50%) scale(.7)`, opacity: 0 }], { duration: 950, delay: i * 60, easing: 'cubic-bezier(.2,.8,.3,1)', fill: 'backwards' }).onfinish = () => c.remove();
      setTimeout(() => c.remove(), 1600);
    });
    toast(`<i class="fa-solid fa-cloud-arrow-up"></i><span>${files.length > 1 ? files.length + ' archivos' : esc(files[0].name.length > 30 ? files[0].name.slice(0, 27) + '…' : files[0].name)}</span>`, x, y - 30);
  }

  /* ── Deslizadores ──────────────────────────────────────── */
  let bubble = null, bubTimer = 0, lastTickV = null, lastTickT = 0;
  function slideTick(el){
    const f = fx(), j = J(); if (!f || !j || !j.soundOn || document.hidden) return;
    const min = +el.min || 0, max = +el.max || 100, v = +el.value, u = max > min ? (v - min) / (max - min) : 0;
    const now = performance.now(); if (now - lastTickT < 45 || v === lastTickV) return; lastTickT = now; lastTickV = v;
    try { f.tone(420 + u * 900, .035, { type: 'sine', vol: .12 }); } catch (e) {}
  }
  function showBubble(el){
    if (!moving()) return;
    if (!bubble){ bubble = document.createElement('div'); bubble.className = 'g7t-bubble'; document.body.appendChild(bubble); }
    const r = el.getBoundingClientRect(), min = +el.min || 0, max = +el.max || 100, v = +el.value, u = max > min ? (v - min) / (max - min) : 0;
    const step = el.step && el.step !== 'any' ? +el.step : 1, dec = step < 1 ? Math.min(3, String(step).split('.')[1]?.length || 1) : 0;
    bubble.textContent = v.toFixed(dec); bubble.style.left = (r.left + 9 + u * (r.width - 18)) + 'px'; bubble.style.top = (r.top - 10) + 'px';
    bubble.classList.add('on'); clearTimeout(bubTimer); bubTimer = setTimeout(() => bubble && bubble.classList.remove('on'), 700);
  }
  document.addEventListener('input', e => { const el = e.target; if (el && el.type === 'range'){ showBubble(el); slideTick(el); } }, true);
  document.addEventListener('change', e => {
    const el = e.target; if (!el) return;
    if (el.type === 'color'){ play('pop', 80); bump(el, 1.2); const r = el.getBoundingClientRect(); sparks(r.left + r.width / 2, r.top + r.height / 2, 8, el.value); }
    else if (el.type === 'range'){ const j = J(); if (j && j.soundOn) play('tick', 200); }
    else if (el.tagName === 'SELECT' && root.classList.contains('g7t')){ play('tap', 80); bump(el, 1.03); }
  }, true);

  /* ── Botones de generar / convertir / exportar ─────────── */
  const GO = /(generar|crear|convertir|exportar|descargar|renderizar|procesar|publicar|optimizar|comprimir|armar|aplicar|recolorear|renombrar|subir)/i;
  document.addEventListener('click', e => {
    const b = e.target.closest && e.target.closest('button, .btn, a.btn, [role="button"]'); if (!b || b.disabled) return;
    if (!root.classList.contains('g7t') || !GO.test((b.textContent || '') + ' ' + (b.title || ''))) return;
    if (moving()){ b.classList.remove('g7t-go'); void b.offsetWidth; b.classList.add('g7t-go'); setTimeout(() => b.classList.remove('g7t-go'), 900); }
    const r = b.getBoundingClientRect(); sparks(r.left + r.width / 2, r.top + r.height / 2, 8);
    play('pop', 120);
  }, true);

  /* ── Resultados que aparecen ───────────────────────────── */
  const LIST = /(result|list|grid|histor|items|thumbs|output|files|queue|cards|gallery|galeria|previews?|piezas|lote|strip|camps|campa)/i;
  let ready = false; setTimeout(() => { ready = true; }, 1800);
  let popBudget = 0, budgetAt = 0;
  // Muchas herramientas redibujan la lista entera (innerHTML): solo entra con rebote lo que no se vio antes
  const seenIn = new WeakMap();
  const keyOf = n => {
    const k = n.getAttribute('data-id') || n.getAttribute('data-key') || n.getAttribute('data-name') || '';
    if (k) return 'k:' + k;
    const t = n.querySelector && n.querySelector('[class*="name" i], [class*="title" i], b, strong, h3, h4');
    const im = n.tagName === 'IMG' ? n : n.querySelector && n.querySelector('img');
    return (t ? t.textContent.trim().slice(0, 60) : (n.textContent || '').trim().slice(0, 60)) + '|' + (im ? (im.getAttribute('src') || '').slice(0, 80) : '');
  };
  const isNew = (p, n) => { let s = seenIn.get(p); if (!s){ s = new Set(); seenIn.set(p, s); } const k = keyOf(n); if (s.has(k)) return false; s.add(k); return true; };
  // Lo que ya estaba al cargar cuenta como visto
  setTimeout(() => root.classList.contains('g7t') && document.querySelectorAll('[class], [id]').forEach(p => { if (LIST.test(typeof p.className === 'string' ? p.className : '') || LIST.test(p.id || '')) [...p.children].forEach(c => isNew(p, c)); }), 1700);
  const mo = new MutationObserver(muts => {
    if (!ready || !moving() || !root.classList.contains('g7t')) return;
    const now = performance.now(); if (now - budgetAt > 1000){ budgetAt = now; popBudget = 24; }
    let k = 0;
    for (const m of muts){
      const p = m.target; if (!(p instanceof Element) || p.closest('.g7-topbar, #ccw, .g7t-toast, [class*="modal"], [class*="tooltip"]')) continue;
      const parentOk = LIST.test(typeof p.className === 'string' ? p.className : '') || LIST.test(p.id || '');
      for (const n of m.addedNodes){
        if (popBudget <= 0) return;
        if (!(n instanceof HTMLElement) || n.offsetWidth < 30) continue;
        const isImg = n.tagName === 'IMG' && /^(blob|data):/.test(n.getAttribute('src') || '');
        if (!parentOk && !isImg) continue;
        if (!isNew(p, n)) continue;
        popBudget--;
        n.animate([{ opacity: 0, scale: '.86', translate: '0 10px' }, { opacity: 1, scale: '1.03', translate: '0 -2px', offset: .6 }, { opacity: 1, scale: '1', translate: '0 0' }],
          { duration: 420, delay: Math.min(k++, 8) * 45, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' });
        if (isImg) n.classList.add('g7t-flash'), setTimeout(() => n.classList.remove('g7t-flash'), 900);
      }
    }
    if (k > 0) play('tick', 250);
  });
  const startMo = () => mo.observe(document.body, { childList: true, subtree: true });
  if (document.body) startMo(); else document.addEventListener('DOMContentLoaded', startMo);

  /* ── Barras de progreso que llegan al final ────────────── */
  const done = new WeakSet();
  const pmo = new MutationObserver(muts => {
    for (const m of muts){
      const el = m.target; if (!(el instanceof HTMLElement)) continue;
      if (!/progress|bar|fill/i.test(el.className || '') && el.getAttribute('role') !== 'progressbar') continue;
      const w = el.style.width; if (!w || !/%$/.test(w)) continue;
      const v = parseFloat(w);
      if (v >= 99.5 && !done.has(el)){ done.add(el); play('chime', 0); if (moving()){ el.classList.add('g7t-full'); setTimeout(() => el.classList.remove('g7t-full'), 900); } }
      else if (v < 50) done.delete(el);
    }
  });
  const startP = () => pmo.observe(document.body, { attributes: true, attributeFilter: ['style'], subtree: true });
  if (document.body) startP(); else document.addEventListener('DOMContentLoaded', startP);

  /* ── Deshacer / rehacer ────────────────────────────────── */
  addEventListener('keydown', e => {
    if (!root.classList.contains('g7t') || !(e.ctrlKey || e.metaKey)) return;
    const t = e.target; if (t && (t.isContentEditable || /^(INPUT|TEXTAREA)$/.test(t.tagName))) return;
    if (e.key === 'z' || e.key === 'Z') play(e.shiftKey ? 'swoosh' : 'rewind', 80);
    else if (e.key === 'y' || e.key === 'Y') play('swoosh', 80);
  }, true);

  /* ── Opciones que quedan elegidas (sitio, plataforma, pestaña, paso) ── */
  const PICK = /(^|[\s_-])(active|selected|sel|on|current|checked|picked|is-active|chosen)(?=$|[\s_-])/;
  let pickAt = 0;
  const amo = new MutationObserver(muts => {
    if (!ready || !root.classList.contains('g7t')) return;
    for (const m of muts){
      const el = m.target; if (!(el instanceof HTMLElement)) continue;
      const was = PICK.test(m.oldValue || ''), now = PICK.test(typeof el.className === 'string' ? el.className : '');
      if (was || !now || el.offsetWidth < 24 || el.offsetWidth > 520 || el.closest('.g7-topbar, #ccw, .g7t-toast, [class*="tooltip"], [class*="dropdown"]')) continue;
      if (!el.matches('button, a, label, li, [role="tab"], [role="button"], [class*="card" i], [class*="chip" i], [class*="pill" i], [class*="opt" i], [class*="step" i], [class*="tab" i], [class*="plat" i], [class*="site" i], [class*="swatch" i], [class*="item" i]')) continue;
      const t = performance.now(); if (t - pickAt < 90) continue; pickAt = t;
      if (moving()) el.animate([{ scale: '1' }, { scale: '1.07' }, { scale: '.97' }, { scale: '1' }], { duration: 360, easing: 'ease-out' });
      play('tap', 60);
      if (el.offsetWidth > 90){ const r = el.getBoundingClientRect(); sparks(r.left + r.width / 2, r.top + r.height / 2, 6); }
    }
  });
  const startA = () => amo.observe(document.body, { attributes: true, attributeFilter: ['class'], attributeOldValue: true, subtree: true });
  if (document.body) startA(); else document.addEventListener('DOMContentLoaded', startA);

  /* ── Pedidos de generar al servidor (IA, armar, renderizar) ── */
  // Mientras trabaja: el botón que se tocó brilla y le giran chispas. Al terminar: festejo grande. Si falla: se sacude.
  const GEN_ACTION = /(^|_)(generate|gen|generar|ai|ia|build|render|compose|rewrite|improve|suggest|translate)(_|$)|^create_/i;
  // Algunas herramientas llaman distinto a su "generar con IA" (placas: textos = copy)
  const GEN_EXTRA = { placasdeportivas: /^copy$/i, placaspaid: /^copy$/i };
  // Guardar con aviso solo si lo pidió la persona (las placas guardan solas mientras se edita)
  const askedSave = () => { const b = lastTarget && lastTarget.closest && lastTarget.closest('button, .btn, [role="button"], a'); return !!b && performance.now() - lastDownAt < 2500 && /(guard|save|plantilla|template|publicar)/i.test((b.textContent || '') + ' ' + (b.title || '') + ' ' + (b.id || '')); };
  const DONE_TEXT = { 'landing-gen': '¡Landing lista!', 'mailingmaker': '¡Mailing listo!', 'popup-gen': '¡Popup listo!', 'placasdeportivas': '¡Pieza lista!', 'placaspaid': '¡Pieza lista!', 'pages': '¡Página lista!', 'planify': '¡Plan listo!' };
  let genCeleAt = -1e9;
  const ofetch = window.fetch;
  if (ofetch) window.fetch = function(input, init){
    const p = ofetch.apply(this, arguments);
    try {
      if (!root.classList.contains('g7t') || !init || !init.body) return p;
      // Subir archivos (FormData con algún archivo): al volver bien, sonido, chispas y aviso
      if (typeof FormData !== 'undefined' && init.body instanceof FormData){
        let n = 0; try { for (const v of init.body.values()) if (v instanceof Blob) n++; } catch (e) {}
        if (!n) return p;
        const x = px, y = py;
        p.then(r => r.clone().json().catch(() => null).then(j => {
          if (!r.ok || (j && (j.error || j.ok === false))) { play('error', 0); return; }
          play('success', 0); sparks(x, y, 14, '#22c55e');
          toast(`<i class="fa-solid fa-cloud-arrow-up"></i><span>${n > 1 ? n + ' archivos subidos' : '¡Subido!'}</span>`, x, y, 'ok');
        }), () => play('error', 0));
        return p;
      }
      if (typeof init.body !== 'string') return p;
      let body; try { body = JSON.parse(init.body); } catch (e) { return p; }
      const act = String(body && body.action || '');
      // Publicar: festejo grande con cohete
      if (/^publish/i.test(act)){
        p.then(r => r.clone().json().catch(() => null).then(j => {
          if (j === null || !r.ok || j.error || j.ok === false) return;
          const jj = J(); if (jj) jj.celebrate('big', '¡Publicada!', 'fa-rocket');
        }), () => {});
        return p;
      }
      // Subir con JSON (imágenes en base64, como el hero de Landing Gen)
      if (/^upload/i.test(act)){
        const x = px, y = py;
        p.then(r => r.clone().json().catch(() => null).then(j => {
          if (!r.ok || (j && (j.error || j.ok === false))) { play('error', 0); return; }
          play('success', 0); sparks(x, y, 14, '#22c55e');
          toast('<i class="fa-solid fa-cloud-arrow-up"></i><span>¡Subida!</span>', x, y, 'ok');
        }), () => play('error', 0));
        return p;
      }
      // Guardar (plantillas, campañas, borradores): festejo chico y aviso
      if (/^(save|guardar|store|update_tpl|save_tpl)/i.test(act) && !/(pref|setting|config|cfg|state|step|draft_auto|autosave)/i.test(act) && askedSave()){
        const x = px, y = py;
        p.then(r => r.clone().json().catch(() => null).then(j => {
          if (j === null || !r.ok || j.error || j.ok === false) return;
          play('chime', 0); sparks(x, y, 10, '#22c55e');
          toast('<i class="fa-solid fa-floppy-disk"></i><span>Guardado</span>', x, y, 'ok');
        }), () => {});
        return p;
      }
      if (!GEN_ACTION.test(act) && !(GEN_EXTRA[slug] && GEN_EXTRA[slug].test(act))) return p;
      const btn = (lastTarget && lastTarget.closest && lastTarget.closest('button, .btn, [role="button"]')) || null;
      const t0 = performance.now();
      if (btn) btn.classList.add('g7t-busy');
      p.then(r => r.clone().json().catch(() => null).then(j => {
        if (btn) btn.classList.remove('g7t-busy');
        if (j === null && r.ok) return;   // no respondió JSON: no se sabe si salió bien, no se festeja
        const ok = r.ok && j && !j.error && j.ok !== false;
        if (ok){
          const jj = J(), now = performance.now(), long = now - t0 > 2500;
          // Un "generar" que dispara varios pedidos seguidos (placas: el día entero) festeja una vez; el resto, un toque
          if (now - genCeleAt < 5000){ play('pluck', 0); if (btn){ const r = btn.getBoundingClientRect(); sparks(r.left + r.width / 2, r.top + r.height / 2, 8); } }
          else if (jj){ genCeleAt = now; jj.celebrate(long ? 'big' : 'medium', DONE_TEXT[slug] || '¡Listo!', 'fa-wand-magic-sparkles'); }
        } else {
          play('error', 0);
          if (btn && moving()) btn.animate([{ translate: '0' }, { translate: '-6px' }, { translate: '5px' }, { translate: '-3px' }, { translate: '0' }], { duration: 360 });
        }
      }), () => { if (btn) btn.classList.remove('g7t-busy'); play('error', 0); });
    } catch (e) {}
    return p;
  };

  /* ── Avisos propios de cada herramienta (#toast, .toast, .lg-toast…) ── */
  // Cuando la herramienta muestra su aviso: si es de error, sonido de error; si dice que algo quedó listo, festejo chico.
  const TOAST_SEL = '[id*="toast" i]:not(.g7t-toast):not(.g7j-chip):not(#adm-toast):not(#cfg-toast), [class*="toast" i]:not(.g7t-toast):not([class*="g7j"])';
  const GOOD = /(list[oa]s?|generad|cread|guardad|publicad|copiad|descargad|subid|aplicad|convertid|exportad|actualizad|enviad|import)/i;
  const BAD = /(error|no se pudo|fall[oó]|inválid|falta|primero|no hay)/i;
  let toastText = '', toastAt = 0;
  const tmo = new MutationObserver(muts => {
    if (!ready || !root.classList.contains('g7t')) return;
    for (const m of muts){
      const el = (m.target.nodeType === 1 ? m.target : m.target.parentElement); if (!el) continue;
      const t = el.closest && el.closest(TOAST_SEL); if (!t || t.closest('.g7-topbar')) continue;
      const cs = getComputedStyle(t); if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity < .1 && !/show|on|open|visible/.test(t.className)) continue;
      const txt = (t.textContent || '').trim(); if (!txt) continue;
      const now = performance.now(); if (txt === toastText && now - toastAt < 6000) continue; toastText = txt; toastAt = now;
      const bad = /(err|error|bad|danger|fail)/i.test(t.className) || BAD.test(txt);
      if (bad){ play('oops', 0); if (moving()) t.animate([{ translate: '0' }, { translate: '-5px' }, { translate: '4px' }, { translate: '0' }], { duration: 300 }); }
      else if (GOOD.test(txt)){ play('success', 0); const r = t.getBoundingClientRect(); sparks(r.left + r.width / 2, r.top + r.height / 2, 10, '#22c55e'); if (moving()) t.animate([{ scale: '.9' }, { scale: '1.05' }, { scale: '1' }], { duration: 360, easing: SPRING }); }
      break;
    }
  });
  const startT = () => tmo.observe(document.body, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['class'] });
  if (document.body) startT(); else document.addEventListener('DOMContentLoaded', startT);

  /* ── Tarjetas que se inclinan hacia el mouse ───────────── */
  const TILT = '.plat-card, .tc, [class*="tool-card" i], [class*="tpl-card" i], [class*="template" i][class*="card" i]';
  let tiltEl = null;
  addEventListener('pointermove', e => {
    if (!moving() || !root.classList.contains('g7t') || e.pointerType !== 'mouse') return;
    const el = e.target.closest && e.target.closest(TILT);
    if (tiltEl && tiltEl !== el){ tiltEl.style.transform = ''; tiltEl.classList.remove('g7t-tilt'); tiltEl = null; }
    if (!el || el.offsetWidth > 520) return;
    tiltEl = el; el.classList.add('g7t-tilt');
    const r = el.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
    el.style.transform = `perspective(700px) rotateX(${(-y * 7).toFixed(2)}deg) rotateY(${(x * 9).toFixed(2)}deg) translateY(-3px)`;
    el.style.setProperty('--mx', ((x + .5) * 100).toFixed(1) + '%'); el.style.setProperty('--my', ((y + .5) * 100).toFixed(1) + '%');
  }, { passive: true });

  /* ── Vista previa que se actualiza al escribir: un pulso suave para notar el cambio ── */
  let pvAt = 0;
  document.addEventListener('input', e => {
    if (!moving() || !root.classList.contains('g7t')) return;
    const t = e.target; if (!t || !/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) && !t.isContentEditable) return;
    const now = performance.now(); if (now - pvAt < 700) return; pvAt = now;
    setTimeout(() => {
      const pv = [...document.querySelectorAll('[id*="preview" i], [class*="preview" i], [id*="prev-" i], [class*="prev-panel" i], .prev-wrap, [class*="canvas-wrap" i], [class*="stage" i]')]
        .filter(el => el.offsetWidth > 200 && el.offsetHeight > 120 && !el.contains(t)).sort((a, b) => b.offsetWidth * b.offsetHeight - a.offsetWidth * a.offsetHeight)[0];
      if (pv){ pv.classList.remove('g7t-pv'); void pv.offsetWidth; pv.classList.add('g7t-pv'); }
    }, 140);
  }, true);

  /* ══ Por herramienta ═══════════════════════════════════ */

  // Convertidores (WebP, WebP a JPG): cada imagen que termina festeja su ahorro; al terminar el lote, festejo grande
  (function converters(){
    if (!/^(webpmaker|webp_converter|jpg_converter)$/.test(slug)) return;
    const boot = () => {
      const list = document.getElementById('list'); if (!list) return;
      const done = new Set(); let batch = 0, n = 0;
      const nameOf = it => (it.querySelector('.wp-name') || {}).textContent || '';
      new MutationObserver(() => {
        const items = [...list.querySelectorAll('.wp-item')];
        const working = list.querySelector('.wp-spin');
        items.forEach(it => {
          const pct = it.querySelector('.wp-sizes .pct'), k = nameOf(it);
          if (!pct || done.has(k)) return;
          done.add(k); batch++; n++;
          const f = fx(), j = J();
          if (f && j && j.soundOn) try { f.tone(660 * Math.pow(2, Math.min(n, 12) / 12), .09, { type: 'triangle', vol: .22 }); } catch (e) {}
          if (moving()){
            const m = (pct.textContent || '').match(/(\d+)/), v = m ? +m[1] : 0, sign = pct.textContent.trim().charAt(0), t0 = performance.now();
            const step = now => { if (!pct.isConnected) return; const u = Math.min(1, (now - t0) / 600), e = 1 - Math.pow(1 - u, 3); pct.textContent = sign + Math.round(v * e) + '%'; if (u < 1) requestAnimationFrame(step); };
            requestAnimationFrame(step);
            pct.animate([{ scale: '.4' }, { scale: '1.35', offset: .6 }, { scale: '1' }], { duration: 520, easing: SPRING });
            it.classList.add('g7t-gotitem'); setTimeout(() => it.classList.remove('g7t-gotitem'), 900);
          }
        });
        if (!working && batch > 0){
          const cnt = batch; batch = 0; n = 0;
          const sum = (document.getElementById('sum') || {}).textContent || '', m = sum.match(/ahorro\s+(\d+)%/i);
          const j = J(), msg = cnt > 1 ? `¡${cnt} listas!${m ? ' Ahorraste ' + m[1] + '%' : ''}` : (m ? `¡Lista! Ahorraste ${m[1]}%` : '¡Lista!');
          if (j){ if (cnt > 1) j.celebrate('medium', msg, 'fa-feather'); else { j.celebrate('subtle'); const r = list.getBoundingClientRect(); toast(`<i class="fa-solid fa-feather"></i><span>${esc(msg)}</span>`, r.left + r.width / 2, r.top + 40, 'ok'); } }
          window.__g7tLast = msg;
        }
        if (!items.length) done.clear();
      }).observe(list, { childList: true, subtree: true });
    };
    if (document.readyState !== 'loading') boot(); else document.addEventListener('DOMContentLoaded', boot);
  })();

  window.G7Tools = { download: onDownload, copied: onCopy, received, sparks, toast };
})();
