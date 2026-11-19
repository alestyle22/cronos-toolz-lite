/* assets/editor-studio.js — Ambiente "estudio" y respuesta de los editores visuales (constructor del portal, Landing Gen 2.0,
 * Mailing Maker 2.0). No toca la lógica de los editores: observa su DOM (barra, lienzo, estado, avisos) y responde con
 * movimiento y sonido (g7-juice: G7Sound / G7Juice). Estilos: editor-studio.css. Lo carga editor_studio.php con
 * data-tool="portal|landing|mail". Doc: toolz/MOVIMIENTO.md › Editores visuales */
(function () {
  'use strict';
  if (window.__pbs) return; window.__pbs = true;
  const me = document.currentScript, tool = (me && me.dataset.tool) || 'portal';
  const H = document.documentElement; H.classList.add('pbs'); H.dataset.pbs = tool;
  const TOOLS = {
    portal: { name: 'Portal de clientes', icon: 'fa-door-open' },
    landing: { name: 'Landing Gen 2.0', icon: 'fa-rocket' },
    mail: { name: 'Mailing Maker 2.0', icon: 'fa-envelope-open-text' },
    profile: { name: 'Profile Studio', icon: 'fa-circle-user' },
    mockup: { name: 'Mockup Studio', icon: 'fa-layer-group' },
    palette: { name: 'Paletas', icon: 'fa-palette' },
    blog: { name: 'Blog Studio', icon: 'fa-feather-pointed' },
    web: { name: 'Your Web', icon: 'fa-globe' },
    font: { name: 'Typography Studio', icon: 'fa-font' }, logo: { name: 'Logo Studio', icon: 'fa-pen-nib' },   // Cronos Creative Engine (creative/), CREATIVE_ENGINE.md
    icon: { name: 'Icon Studio', icon: 'fa-icons' }, thumb: { name: 'Thumbnail Maker', icon: 'fa-photo-film' },   // yourweb/ (portal con .pbl-h), YOUR_WEB.md   // blog/ (panel con .pbl-h y editor con la barra pbe-*), BLOG_STUDIO.md
  };
  const T = TOOLS[tool];
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const SPRING = 'cubic-bezier(.34,1.56,.64,1)', OUT = 'cubic-bezier(.2,.8,.2,1)';
  const moving = () => H.classList.contains('g7-move');
  const snd = (n, gap) => { try { if (window.G7Sound) window.G7Sound(n, gap); } catch (e) {} };
  const anim = (el, kf, o) => (el && el.animate && moving()) ? el.animate(kf, o) : null;
  const mk = (tag, cls, html) => { const n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; };
  const typing = t => t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
  let tapAt = -1e9; const tapped = () => { tapAt = performance.now(); };   // último toque de la persona (celular: el punto de Opciones)
  document.addEventListener('pointerdown', tapped, true);

  /** Entrada escalonada de una lista de elementos */
  function stagger(els, o = {}) {
    if (!moving()) return;
    els.slice(0, o.max || 28).forEach((el, i) => el.animate([{ opacity: 0, translate: `0 ${o.dy ?? 10}px`, scale: o.s ?? '.97' }, { opacity: 1, translate: '0 0', scale: '1' }],
      { duration: o.dur || 380, delay: (o.d0 || 0) + i * (o.step || 22), easing: OUT, fill: 'backwards' }));
  }

  /* ============================== Listas ============================== */
  function initList() {
    const h = $('.pbl-h'); if (!h) return;
    const ico = mk('div', 'pbs-hero-ico', `<i class="fa-solid ${T.icon}"></i>`); ico.setAttribute('aria-hidden', 'true');
    const txt = h.querySelector(':scope > div'); if (txt) h.insertBefore(ico, txt);
    anim(ico, [{ scale: '.4', rotate: '-20deg', opacity: 0 }, { scale: '1.08', rotate: '4deg', opacity: 1, offset: .7 }, { scale: '1', rotate: '0deg' }], { duration: 640, easing: SPRING });
    stagger([...h.children, ...$$('.lp2-tools > *, .mm2-tools > *')], { step: 50, dy: 8 });
    // Tarjetas: cada vez que la lista se redibuja, entran escalonadas (solo las nuevas)
    const seen = new WeakSet(); let t = 0;
    const scan = () => { const fresh = $$('.pbl-card, .pbl-empty, .lp2-lead').filter(c => !seen.has(c)); fresh.forEach(c => seen.add(c)); if (fresh.length) stagger(fresh, { step: 40, dy: 16, s: '.96', dur: 460 }); };
    new MutationObserver(() => { clearTimeout(t); t = setTimeout(scan, 30); }).observe($('.pbl') || document.body, { childList: true, subtree: true });
    scan();
  }

  /* ============================== Editor ============================== */
  function initEditor() {
    const top = $('.pbe-top'), stage = $('#pbe-stage') || $('#mm-stage'); if (!top || !stage) return;
    const P = tool === 'mail' ? 'mm' : 'pbe';
    const frame = $('#pbe-frame') || $('#mm-frame');

    // Identidad: ícono de la herramienta y su nombre arriba del nombre del documento
    const back = top.querySelector('.pbe-icon'), title = top.querySelector('.pbe-title');
    const id = mk('div', 'pbs-id', `<i class="fa-solid ${T.icon}"></i>`); id.title = T.name; id.setAttribute('aria-hidden', 'true');
    if (title) top.insertBefore(id, title);
    anim(id, [{ scale: '.3', rotate: '-30deg' }, { scale: '1.12', rotate: '6deg', offset: .7 }, { scale: '1', rotate: '0deg' }], { duration: 700, easing: SPRING });
    stagger([...top.children].filter(c => c !== back), { step: 30, dy: -6, s: '1' });

    // Íconos sueltos de la barra → un grupo
    const ids = ['undo', 'redo', 'hist', 'more', 'prev'].map(k => $('#' + P + '-' + k)).filter(Boolean);
    if (ids.length > 1) { const g = mk('div', 'pbs-grp'); g.setAttribute('role', 'group'); ids[0].before(g); ids.forEach(b => g.append(b)); }

    // Dispositivo: píldora que se desliza
    const dev = $('.pbe-dev'), pill = dev && dev.appendChild(mk('span', 'pbs-devpill'));
    const placePill = () => { const on = dev && dev.querySelector('button.on'); if (!on || !pill) return; pill.style.left = on.offsetLeft + 'px'; pill.style.width = on.offsetWidth + 'px'; };

    // Pestañas del panel izquierdo: indicador que se desliza
    const tabs = $('.pbe-left .pbe-tabs'), ind = tabs && tabs.appendChild(mk('span', 'pbs-tabind'));
    const placeInd = () => { const on = tabs && tabs.querySelector('button.on'); if (!on || !ind) return; ind.style.left = (on.offsetLeft + 10) + 'px'; ind.style.width = (on.offsetWidth - 20) + 'px'; };
    if (tabs) tabs.addEventListener('click', e => {
      const b = e.target.closest('button[data-tab]'); if (!b) return;
      requestAnimationFrame(() => { placeInd(); const pane = $(`.pbe-left .pbe-pane[data-pane="${b.dataset.tab}"]`); if (pane) stagger([...pane.querySelectorAll(':scope > * > *, :scope > *')].filter(n => n.offsetParent).slice(0, 18), { step: 18, dy: 8 }); });
      anim(b.querySelector('i'), [{ scale: '1', rotate: '0deg' }, { scale: '1.3', rotate: '-10deg' }, { scale: '1', rotate: '0deg' }], { duration: 380, easing: SPRING });
    });

    // Barra flotante del escenario
    const bar = mk('div', 'pbs-bar', `<span class="pbs-dev"><i class="fa-solid fa-desktop"></i><b>Computadora</b></span><span class="pbs-sep"></span>
      <span class="pbs-w" title="Ancho del lienzo"><b>—</b>&nbsp;px</span><span class="pbs-sep pbs-hide-s"></span>
      <span class="pbs-page pbs-hide-s"></span>
      <button type="button" class="pbs-kbtn" aria-haspopup="dialog" title="Atajos de teclado (?)"><i class="fa-regular fa-keyboard"></i><span class="pbs-hide-s">Atajos</span></button>`);
    bar.setAttribute('role', 'status'); bar.setAttribute('aria-live', 'off');
    document.body.append(bar);
    const cue = stage.appendChild(mk('div', 'pbs-dropcue', '<i class="fa-solid fa-hand-pointer"></i> Suelta en el lienzo'));
    const placeBar = () => { const r = stage.getBoundingClientRect(); bar.style.left = (r.left + r.width / 2) + 'px'; bar.hidden = r.width < 330; cue.style.position = tool === 'mail' ? 'fixed' : ''; if (tool === 'mail') { cue.style.left = (r.left + r.width / 2) + 'px'; cue.style.top = (r.top + 14) + 'px'; } };
    const wEl = bar.querySelector('.pbs-w b'), pgEl = bar.querySelector('.pbs-page');
    let lastW = '';
    const syncW = () => {
      if (!frame) return; const w = Math.round(frame.offsetWidth), z = w ? Math.round(frame.getBoundingClientRect().width / w * 100) : 100;
      const k = w + '|' + z; if (k === lastW) return; lastW = k;
      wEl.textContent = w; wEl.parentNode.title = 'Ancho del lienzo' + (z !== 100 ? ` (se ve al ${z} %)` : '');
      wEl.nextSibling.textContent = ' px' + (z !== 100 && tool !== 'mail' ? ` · ${z} %` : '');
    };
    const syncDev = (first) => {
      const on = dev && dev.querySelector('button.on'); if (!on) return;
      const name = (on.getAttribute('aria-label') || on.title || '').replace(/\s*\(.*\)/, '');
      const ic = on.querySelector('i'); bar.querySelector('.pbs-dev').innerHTML = `<i class="${ic ? ic.className : 'fa-solid fa-desktop'}"></i><b>${name}</b>`;
      placePill(); if (!first) anim(bar.querySelector('.pbs-dev'), [{ scale: '.8' }, { scale: '1.1' }, { scale: '1' }], { duration: 380, easing: SPRING });
    };
    const syncPage = () => {
      const sel = $('#pbe-page'), z = $('#mm-zfit');
      if (sel && sel.selectedOptions[0]) pgEl.innerHTML = `<i class="fa-regular fa-file"></i><b></b>`, pgEl.querySelector('b').textContent = sel.selectedOptions[0].textContent;
      else if (z) pgEl.innerHTML = `<i class="fa-solid fa-magnifying-glass"></i><b></b>`, pgEl.querySelector('b').textContent = z.textContent;
      else pgEl.hidden = true;
    };
    if (frame && window.ResizeObserver) { const ro = new ResizeObserver(syncW); ro.observe(frame); ro.observe(frame.parentNode); }
    if (frame) new MutationObserver(syncW).observe(frame, { attributes: true, attributeFilter: ['style'] });
    if (dev) {
      new MutationObserver(() => syncDev()).observe(dev, { attributes: true, subtree: true, attributeFilter: ['class'] });
      dev.addEventListener('click', e => { const b = e.target.closest('button'); if (!b || b.classList.contains('on')) return; snd('swoosh', 300);
        const box = $('#pbe-frame-wrap') || frame; setTimeout(() => anim(box, [{ scale: '1' }, { scale: '.985' }, { scale: '1' }], { duration: 520, easing: SPRING }), 30); });
    }
    const pageSel = $('#pbe-page'); if (pageSel) { pageSel.addEventListener('change', () => { syncPage(); snd('swoosh', 300); flashFrame(); }); new MutationObserver(syncPage).observe(pageSel, { childList: true }); }
    const z = $('#mm-zfit'); if (z) new MutationObserver(syncPage).observe(z, { childList: true, characterData: true, subtree: true });
    addEventListener('resize', () => { placeBar(); placePill(); placeInd(); });
    syncDev(true); syncPage(); syncW(); placeBar();
    setTimeout(() => { placePill(); placeInd(); placeBar(); }, 300);   // las fuentes cambian los anchos
    // el centrado va por transform (CSS); la entrada mueve la propiedad translate, que se suma
    anim(bar, [{ opacity: 0, translate: '0 20px' }, { opacity: 1, translate: '0 0' }], { duration: 520, delay: 250, easing: SPRING, fill: 'backwards' });

    /* ── Atajos ── */
    const KEYS = [['Z', 'Deshacer'], ['Y', 'Rehacer'], ['D', 'Duplicar bloque'], ...(tool === 'mail' ? [['C', 'Copiar bloque'], ['V', 'Pegar']] : []), ['↑ ↓', 'Mover bloque'], ['S', 'Guardar ahora']];
    let keysBox = null;
    function toggleKeys(force) {
      if (keysBox && force !== true) { const k = keysBox; keysBox = null; const a = anim(k, [{ opacity: 1, scale: '1' }, { opacity: 0, scale: '.94' }], { duration: 160 }); a ? (a.onfinish = () => k.remove()) : k.remove(); snd('close'); return; }
      if (keysBox) return;
      keysBox = mk('div', 'pbs-keys', `<h4><i class="fa-regular fa-keyboard"></i> Atajos del editor</h4>` +
        KEYS.map(([k, t]) => `<div><span>${t}</span><span><kbd>Ctrl</kbd>${k.split(' ').map(x => `<kbd>${x}</kbd>`).join('')}</span></div>`).join('') +
        `<div><span>Borrar bloque</span><span><kbd>Supr</kbd></span></div><div><span>Soltar la selección</span><span><kbd>Esc</kbd></span></div><div><span>Ver esta ayuda</span><span><kbd>?</kbd></span></div>`);
      keysBox.setAttribute('role', 'dialog'); keysBox.setAttribute('aria-label', 'Atajos del editor');
      document.body.append(keysBox);
      const r = bar.getBoundingClientRect(); keysBox.style.left = Math.max(10, Math.min(innerWidth - 300, r.right - 290)) + 'px'; keysBox.style.bottom = (innerHeight - r.top + 10) + 'px';
      snd('open'); anim(keysBox, [{ opacity: 0, scale: '.9', translate: '0 10px' }, { opacity: 1, scale: '1', translate: '0 0' }], { duration: 340, easing: SPRING });
      stagger($$('div', keysBox), { step: 25, dy: 6, d0: 60 });
    }
    bar.querySelector('.pbs-kbtn').addEventListener('click', e => { e.stopPropagation(); toggleKeys(); });
    document.addEventListener('pointerdown', e => { if (keysBox && !keysBox.contains(e.target) && !e.target.closest('.pbs-kbtn')) toggleKeys(); });

    /* ── Teclas: se muestran en el escenario y responden ── */
    const selected = () => tool === 'mail' ? !!($('#mm-sel') && !$('#mm-sel').hidden) : !!(fdoc() && fdoc().querySelector('.pbc-on'));
    const fdoc = () => { try { return frame && frame.contentDocument; } catch (e) { return null; } };
    let capT = 0, cap = null;
    function keycap(keys, text, icon) {
      if (cap) cap.remove(); clearTimeout(capT);
      cap = mk('div', 'pbs-keycap', keys.map(k => `<kbd class="pbs-k">${k}</kbd>`).join('') + `<span>${icon ? `<i class="fa-solid ${icon}"></i> ` : ''}${text}</span>`);
      document.body.append(cap);
      const r = stage.getBoundingClientRect(); cap.style.left = (r.left + r.width / 2) + 'px'; cap.style.top = (r.bottom - 112) + 'px';
      anim(cap, [{ opacity: 0, scale: '.7', translate: '0 12px' }, { opacity: 1, scale: '1.05', translate: '0 0', offset: .6 }, { opacity: 1, scale: '1', translate: '0 0' }], { duration: 380, easing: SPRING });
      const c = cap; capT = setTimeout(() => { const a = anim(c, [{ opacity: 1 }, { opacity: 0, translate: '0 -8px' }], { duration: 260, fill: 'forwards' }); a ? (a.onfinish = () => c.remove()) : c.remove(); if (cap === c) cap = null; }, 1100);
    }
    function onKey(e) {
      if (e.repeat || typing(e.target)) return;
      const mod = e.ctrlKey || e.metaKey, k = (e.key || '').toLowerCase();
      if (!mod && (e.key === '?' || (!e.key && e.shiftKey && e.code === 'Slash'))) { toggleKeys(); return; }
      if (!mod && e.key === 'Escape' && keysBox) { toggleKeys(); return; }
      const can = id => { const b = $('#' + P + '-' + id); return b && !b.disabled; };
      if (mod && k === 's') { keycap(['Ctrl', 'S'], 'Guardado', 'fa-check'); return; }
      if (mod && k === 'z' && !e.shiftKey) { if (can('undo')) { keycap(['Ctrl', 'Z'], 'Deshacer', 'fa-rotate-left'); rewind('undo'); } return; }
      if (mod && (k === 'y' || (k === 'z' && e.shiftKey))) { if (can('redo')) { keycap(['Ctrl', 'Y'], 'Rehacer', 'fa-rotate-right'); rewind('redo'); } return; }
      if (!selected()) { if (mod && k === 'v' && tool === 'mail') { keycap(['Ctrl', 'V'], 'Pegar', 'fa-paste'); snd('pop'); } return; }
      if (mod && k === 'd') { keycap(['Ctrl', 'D'], 'Duplicado', 'fa-clone'); snd('pop'); landSel(); }
      else if (mod && k === 'c' && tool === 'mail') { keycap(['Ctrl', 'C'], 'Copiado', 'fa-copy'); snd('tick'); }
      else if (mod && k === 'v' && tool === 'mail') { keycap(['Ctrl', 'V'], 'Pegado', 'fa-paste'); snd('pop'); landSel(); }
      else if (e.key === 'Delete' || e.key === 'Backspace') { keycap(['Supr'], 'Borrado', 'fa-trash-can'); snd('swoosh'); }
      else if (mod && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { keycap(['Ctrl', e.key === 'ArrowUp' ? '↑' : '↓'], e.key === 'ArrowUp' ? 'Subido' : 'Bajado', e.key === 'ArrowUp' ? 'fa-arrow-up' : 'fa-arrow-down'); snd('drop'); landSel(); }
    }
    addEventListener('keydown', onKey, true);   // antes que el editor: así se sabe si había algo para deshacer

    // Deshacer / rehacer con los botones
    function rewind(which) {
      snd('rewind');
      const b = $('#' + P + '-' + which); anim(b && b.querySelector('i'), [{ rotate: '0deg' }, { rotate: which === 'undo' ? '-360deg' : '360deg' }], { duration: 520, easing: OUT });
      flashFrame();
    }
    ['undo', 'redo'].forEach(w => { const b = $('#' + P + '-' + w); if (b) b.addEventListener('click', () => { if (!b.disabled) rewind(w); }, true); });
    function flashFrame() { const box = $('#pbe-frame-wrap') || frame; anim(box, [{ filter: 'brightness(1)' }, { filter: 'brightness(1.18) saturate(1.1)' }, { filter: 'brightness(1)' }], { duration: 420, easing: 'ease-out' }); }

    /* ── Arrastrar y soltar ── */
    let drag = false, dropped = false;
    const startDrag = (src) => { drag = true; dropped = false; document.body.classList.add('pbs-drag'); snd('lift', 200);
      if (src) anim(src, [{ scale: '1' }, { scale: '.9' }, { scale: '1' }], { duration: 360, easing: SPRING }); };
    const endDrag = () => { if (!drag) return; drag = false; document.body.classList.remove('pbs-drag'); };
    document.addEventListener('dragstart', e => { const src = e.target.closest && e.target.closest('.pbe-blk, .pbe-struct, .mm-struct, [draggable="true"], .mm-grip'); startDrag(src); }, true);
    document.addEventListener('dragend', () => setTimeout(endDrag, 0), true);
    document.addEventListener('drop', e => { if (drag) landAt(e.clientX, e.clientY); }, true);
    function hookFrame() {
      const d = fdoc(); if (!d || d.__pbs) return; d.__pbs = true;
      d.addEventListener('dragstart', e => startDrag(e.target && e.target.closest ? e.target.closest('[data-pb]') : null), true);
      d.addEventListener('dragend', () => setTimeout(endDrag, 0), true);
      d.addEventListener('drop', e => {
        const r = frame.getBoundingClientRect(), k = frame.offsetWidth ? r.width / frame.offsetWidth : 1;
        landAt(r.left + e.clientX * k, r.top + e.clientY * k); setTimeout(endDrag, 0);
      }, true);
      d.addEventListener('keydown', onKey, true);
      d.addEventListener('pointerdown', tapped, true);
    }
    if (frame) { frame.addEventListener('load', hookFrame); hookFrame(); }
    function landAt(x, y) {
      if (dropped) return; dropped = true; snd('drop', 150);
      if (!moving()) return;
      const ring = mk('div', 'pbs-drop'); ring.style.left = x + 'px'; ring.style.top = y + 'px'; document.body.append(ring);
      const a = ring.animate([{ scale: '.4', opacity: 1 }, { scale: '5', opacity: 0 }], { duration: 560, easing: 'cubic-bezier(.1,.7,.3,1)' }); a.onfinish = () => ring.remove();
      for (let i = 0; i < 10; i++) {
        const p = mk('div', 'pbs-dot'), ang = i / 10 * Math.PI * 2, dist = 26 + Math.random() * 22; p.style.left = x + 'px'; p.style.top = y + 'px';
        if (i % 2) p.style.background = 'var(--pbs-a)'; document.body.append(p);
        const b = p.animate([{ translate: '0 0', opacity: 1, scale: '1' }, { translate: `${Math.cos(ang) * dist}px ${Math.sin(ang) * dist}px`, opacity: 0, scale: '.3' }], { duration: 520 + Math.random() * 200, easing: 'cubic-bezier(.1,.7,.3,1)' });
        b.onfinish = () => p.remove();
      }
      landSel();
    }
    // Lo recién puesto (queda elegido) hace un pequeño rebote
    function landSel() {
      setTimeout(() => {
        if (tool === 'mail') { const s = $('#mm-sel'); if (s && !s.hidden) anim(s, [{ scale: '.94', opacity: .3 }, { scale: '1.02', opacity: 1, offset: .6 }, { scale: '1' }], { duration: 460, easing: SPRING }); return; }
        const d = fdoc(), n = d && d.querySelector('.pbc-on'); if (n && n.animate) n.animate([{ scale: '.96', opacity: .4 }, { scale: '1.01', opacity: 1, offset: .6 }, { scale: '1' }], { duration: 480, easing: SPRING });
      }, tool === 'mail' ? 90 : 520);
    }

    /* ── Estado de guardado ── */
    const st = $('#' + P + '-status');
    if (st) { let prev = st.className;
      new MutationObserver(() => { st.title = st.textContent; }).observe(st, { childList: true, characterData: true, subtree: true });
      new MutationObserver(() => { const c = st.className; if (c === prev) return; const was = prev; prev = c;
        if (/\b(ok|draft)\b/.test(c) && /\bbusy\b/.test(was)) anim(st, [{ scale: '.85' }, { scale: '1.08' }, { scale: '1' }], { duration: 420, easing: SPRING });
        else if (/\bbad\b/.test(c)) anim(st, [{ translate: '0 0' }, { translate: '-5px 0' }, { translate: '5px 0' }, { translate: '-3px 0' }, { translate: '0 0' }], { duration: 380 });
      }).observe(st, { attributes: true, attributeFilter: ['class'] }); }

    /* ── Publicar / exportar: festejo ── */
    let lastParty = 0;
    new MutationObserver(ms => {
      for (const m of ms) for (const n of m.addedNodes) if (n.classList && n.classList.contains('pbe-spark') && performance.now() - lastParty > 1500) {
        lastParty = performance.now();
        if (window.G7Juice) G7Juice.celebrate('medium', tool === 'landing' ? '¡En línea!' : '¡Publicado!', 'fa-rocket');
        const b = $('#pbe-publish'); anim(b, [{ scale: '1' }, { scale: '1.12' }, { scale: '.96' }, { scale: '1' }], { duration: 600, easing: SPRING });
        return;
      }
    }).observe(document.body, { childList: true });
    const dlg = $('#mm-dlg');
    if (dlg) dlg.addEventListener('click', e => { if (e.target.closest('[download], a[href^="blob:"]') && window.G7Juice) G7Juice.celebrate('small'); });

    /* ── Panel de opciones: entra suave cuando cambia lo elegido ── */
    const insp = $('#' + P + '-insp');
    if (insp) { let key = '';
      new MutationObserver(() => {
        const h = insp.querySelector('.pbe-ih b, h3, h2'), k = (h ? h.textContent : '') + '|' + insp.childElementCount;
        if (k === key) return; key = k;
        stagger([...insp.children].slice(0, 12), { step: 28, dy: 10, s: '1', dur: 340 });
        const ic = insp.querySelector('.pbe-ih > i'); anim(ic, [{ scale: '.5', rotate: '-25deg' }, { scale: '1.12', rotate: '5deg', offset: .65 }, { scale: '1', rotate: '0deg' }], { duration: 520, easing: SPRING });
      }).observe(insp, { childList: true });
    }

    initPhone(P);

    /* ── Paleta: aparece escalonada la primera vez ── */
    const pal = $('#pbe-palette') || $('#mm-comps');
    if (pal) { const go = () => { const t = $$('.pbe-blk, .pbe-struct, .mm-struct', pal.closest('.pbe-pane') || pal); if (!t.length) return false; stagger(t, { step: 16, dy: 12, s: '.9', d0: 120, max: 36 }); return true; };
      if (!go()) { const mo = new MutationObserver(() => { if (go()) mo.disconnect(); }); mo.observe(pal, { childList: true, subtree: true }); } }
  }

  /* ============================== Celular: un panel por vez ==============================
     En pantallas angostas (≤768 px, editor-studio.css › "Celular") los tres paneles no entran lado a lado: se ve uno
     y una barra abajo cambia entre Bloques (panel izquierdo), Lienzo y Opciones (panel derecho). Agregar un bloque
     con un toque vuelve al lienzo para verlo; elegir algo marca Opciones con un punto. En la computadora no hace nada. */
  function initPhone(P, o = {}) {
    // Selectores (no elementos): Profile Studio vuelve a dibujar sus paneles cada vez que se abre un proyecto
    o = Object.assign({ left: '.pbe-left', right: '.pbe-right', stage: '#pbe-stage, #mm-stage', add: '.pbe-blk, .mm-struct, .mm-saved-b, .pbe-left .pbe-struct',
      tabs: [['add', 'fa-shapes', tool === 'mail' ? 'Componentes' : 'Bloques'], ['stage', 'fa-pen-ruler', tool === 'mail' ? 'Email' : 'Lienzo'], ['opts', 'fa-sliders', 'Opciones']] }, o);
    if (!o.lazy && (!$('.pbe-main') || !$(o.left) || !$(o.right))) return null;
    const mq = matchMedia('(max-width:768px)');
    const nav = mk('nav', 'pbs-mnav', o.tabs
      .map(([k, ic, t]) => `<button type="button" data-m="${k}" aria-pressed="false"><i class="fa-solid ${ic}"></i><span>${t}</span></button>`).join(''));
    nav.setAttribute('aria-label', 'Qué ver'); document.body.append(nav);
    let cur = '';
    const show = (k, quiet, force) => {
      if (k === cur && !force) return; cur = k; H.dataset.pbsM = k;
      $$('button', nav).forEach(b => { const on = b.dataset.m === k; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); if (on) b.classList.remove('dot'); });
      if (!quiet) { snd('tick', 120); const pane = $(k === 'add' ? o.left : k === 'opts' ? o.right : o.stage);
        anim(pane, [{ opacity: 0, translate: '0 10px' }, { opacity: 1, translate: '0 0' }], { duration: 260, easing: OUT }); }
      dispatchEvent(new Event('resize'));   // el lienzo vuelve a medir su ancho al reaparecer
    };
    nav.addEventListener('click', e => { const b = e.target.closest('button[data-m]'); if (b) show(b.dataset.m); });
    // Un toque en la paleta agrega el bloque (o aplica la plantilla): se vuelve al lienzo para verlo
    document.addEventListener('click', e => {
      if (!mq.matches || !e.target.closest(o.left) || !e.target.closest(o.add)) return;
      setTimeout(() => show('stage'), 140);
    });
    // Algo nuevo en Opciones (se eligió un bloque): punto en la pestaña si no está a la vista
    const insp = $('#' + P + '-insp');
    if (insp) { let key = '';
      new MutationObserver(() => {
        const h = insp.querySelector('.pbe-ih b, h3, h2'), k = h ? h.textContent : ''; if (k === key) return; key = k;
        if (!mq.matches || cur === 'opts' || performance.now() - tapAt > 1500) return;   // solo si lo pidió un toque (al abrir no cuenta)
        const b = $('button[data-m="opts"]', nav); b.classList.add('dot');
        anim(b, [{ scale: '1' }, { scale: '1.15' }, { scale: '1' }], { duration: 380, easing: SPRING });
      }).observe(insp, { childList: true, subtree: true });
    }
    show('stage', true);
    // Portal y Landing: el dispositivo solo cambia el ancho de la vista previa; en el celular se arranca viendo "Celular"
    // (la computadora entraría al 27 %). En Mailing el dispositivo cambia qué se edita, así que no se toca.
    if (mq.matches && (tool === 'portal' || tool === 'landing')) setTimeout(() => { const m = $('.pbe-dev button[data-dev="m"]'); if (m && !m.classList.contains('on')) m.click(); }, 400);   // después de que el editor conecte sus botones
    return { show };
  }

  /* ============================== Profile Studio ==============================
     Una sola página (social/perfiles/app.js) que dibuja la lista o el editor dentro de #pf. Acá no se toca su lógica:
     se mira cuándo aparece cada pantalla y se le da lo mismo que a los otros estudios (identidad, barra agrupada,
     indicador de pestañas, movimiento, sonido, teclas en pantalla y un panel por vez en el celular).
     El aspecto va en editor-studio.css › "Profile Studio". */
  function initProfile() {
    const root = $('#pf'); if (!root) return;
    const phone = initPhone('pf', { lazy: true, left: '#pf-left', right: '#pf-right', stage: '#pf-stage', add: '.pf-tpl, .pf-res .pf-btn',
      tabs: [['add', 'fa-swatchbook', 'Diseño'], ['stage', 'fa-circle-user', 'Vista'], ['opts', 'fa-sliders', 'Ajustes']] });
    const seen = new WeakSet();
    let hd = null, ed = null, lastTab = null, tabClick = false;

    // ── Lista: ícono de la herramienta y tarjetas que entran escalonadas (no mientras se escribe en el buscador)
    function home() {
      const h = $('.pf-head', root); if (!h || h === hd) return;
      const first = !hd; hd = h;
      const ico = mk('div', 'pbs-hero-ico', `<i class="fa-solid ${T.icon}"></i>`); ico.setAttribute('aria-hidden', 'true'); h.prepend(ico);
      const cards = $$('.pf-big, .pf-tpl, .pf-card, .pf-empty', root);
      if (document.activeElement && document.activeElement.id === 'pf-q') { cards.forEach(c => seen.add(c)); return; }
      const fresh = cards.filter(c => !seen.has(c)); fresh.forEach(c => seen.add(c));
      if (first) { anim(ico, [{ scale: '.4', rotate: '-20deg', opacity: 0 }, { scale: '1.08', rotate: '4deg', opacity: 1, offset: .7 }, { scale: '1', rotate: '0deg' }], { duration: 640, easing: SPRING });
        stagger([...h.children].slice(1), { step: 50, dy: 8 }); }
      stagger(fresh, { step: 14, dy: 14, s: '.96', dur: 420, max: 40, d0: first ? 120 : 0 });
    }

    // ── Editor
    function editor() {
      const e = $('.pf-ed', root); if (!e || e === ed) return; ed = e;
      const name = $('#pf-name', e);
      const id = mk('div', 'pbs-id', `<i class="fa-solid ${T.icon}"></i>`); id.title = T.name; id.setAttribute('aria-hidden', 'true');
      if (name) name.before(id);
      anim(id, [{ scale: '.3', rotate: '-30deg' }, { scale: '1.12', rotate: '6deg', offset: .7 }, { scale: '1', rotate: '0deg' }], { duration: 700, easing: SPRING });
      const icons = ['undo', 'redo', 'before', 'history'].map(a => $(`.pf-bar > [data-a="${a}"]`, e)).filter(Boolean);
      if (icons.length > 1) { const g = mk('div', 'pbs-grp'); g.setAttribute('role', 'group'); icons[0].before(g); icons.forEach(b => g.append(b)); }
      stagger([...$('.pf-bar', e).children].slice(1), { step: 30, dy: -6, s: '1' });
      anim($('#pf-cv', e), [{ opacity: 0, scale: '.94' }, { opacity: 1, scale: '1' }], { duration: 520, delay: 120, easing: SPRING, fill: 'backwards' });
      const left = $('#pf-left', e);
      if (left) { new MutationObserver(tabsChanged).observe(left, { childList: true }); tabsChanged(); }
      if (phone) phone.show('stage', true, true);   // cada proyecto arranca viendo la foto
    }
    // Las pestañas se dibujan de nuevo en cada cambio: el indicador sale de donde estaba y se desliza a la nueva
    function tabsChanged() {
      const tabs = ed && $('#pf-left .pf-tabs', ed); if (!tabs) return;
      const on = $('button.on', tabs); if (!on || !on.offsetWidth) return;   // panel oculto (celular): se ubica al mostrarse
      const old = $('.pbs-tabind', tabs), ind = old || tabs.appendChild(mk('span', 'pbs-tabind'));
      const to = { l: on.offsetLeft + 8, w: Math.max(12, on.offsetWidth - 16) };
      if (!old && lastTab) { ind.style.transition = 'none'; ind.style.left = lastTab.l + 'px'; ind.style.width = lastTab.w + 'px'; void ind.offsetWidth; ind.style.transition = ''; }
      ind.style.left = to.l + 'px'; ind.style.width = to.w + 'px'; lastTab = to;
      if (tabClick) { tabClick = false;
        anim($('i', on), [{ scale: '1', rotate: '0deg' }, { scale: '1.3', rotate: '-10deg' }, { scale: '1', rotate: '0deg' }], { duration: 380, easing: SPRING });
        stagger($$('#pf-left .pf-blk, #pf-left .pf-tpl', ed).slice(0, 24), { step: 22, dy: 8, s: '1', dur: 320 }); }
    }
    addEventListener('resize', () => { lastTab = null; tabsChanged(); });

    document.addEventListener('click', e => {
      const t = e.target.closest('[data-a]'); if (!t || !root.contains(t)) return;
      const a = t.dataset.a;
      if (a === 'tab') { tabClick = true; snd('tick', 100); }
      else if (a === 'undo' || a === 'redo') { snd('rewind'); anim($('i', t), [{ rotate: '0deg' }, { rotate: a === 'undo' ? '-360deg' : '360deg' }], { duration: 520, easing: OUT }); }
    }, true);

    // Teclas del editor: se muestran sobre la vista
    addEventListener('keydown', e => {
      if (!document.body.classList.contains('pf-editing') || e.repeat || typing(e.target) || !(e.ctrlKey || e.metaKey)) return;
      const k = (e.key || '').toLowerCase();
      const map = { z: ['Z', 'Deshacer', 'fa-rotate-left'], y: ['Y', 'Rehacer', 'fa-rotate-right'], s: ['S', 'Versión guardada', 'fa-check'], e: ['E', 'Exportar', 'fa-download'] };
      const m = map[k === 'z' && e.shiftKey ? 'y' : k]; if (m) showCap($('#pf-stage'), ['Ctrl', m[0]], m[1], m[2]);
    }, true);

    const watch = () => { home(); editor(); };
    new MutationObserver(watch).observe(root, { childList: true }); watch();
  }

  /** Tecla apretada: una pastilla sobre el escenario que se va sola */
  let capEl = null, capT = 0;
  function showCap(stage, keys, text, icon) {
    if (!stage) return; if (capEl) capEl.remove(); clearTimeout(capT);
    capEl = mk('div', 'pbs-keycap', keys.map(k => `<kbd class="pbs-k">${k}</kbd>`).join('') + `<span>${icon ? `<i class="fa-solid ${icon}"></i> ` : ''}${text}</span>`);
    document.body.append(capEl);
    const r = stage.getBoundingClientRect(); capEl.style.left = (r.left + r.width / 2) + 'px'; capEl.style.top = (r.bottom - 112) + 'px';
    anim(capEl, [{ opacity: 0, scale: '.7', translate: '0 12px' }, { opacity: 1, scale: '1.05', translate: '0 0', offset: .6 }, { opacity: 1, scale: '1', translate: '0 0' }], { duration: 380, easing: SPRING });
    const c = capEl; capT = setTimeout(() => { const a = anim(c, [{ opacity: 1 }, { opacity: 0, translate: '0 -8px' }], { duration: 260, fill: 'forwards' }); a ? (a.onfinish = () => c.remove()) : c.remove(); if (capEl === c) capEl = null; }, 1100);
  }

  /* ============================== Avisos y ventanas (las dos pantallas) ==============================
     Los avisos se muestran con hidden y las ventanas son <dialog>: g7-juice solo mira clases, así que acá se les da sonido y entrada. */
  function initNotices() {
    const hooked = new WeakSet();
    const toast = t => {
      if (hooked.has(t)) return; hooked.add(t); let lastTxt = '', lastAt = 0;
      new MutationObserver(() => {
        if (t.hidden || (t.classList.contains('pf-toast') && !t.classList.contains('on'))) return;   // Profile Studio muestra el aviso con la clase "on"
        const txt = t.textContent, now = performance.now();
        if (txt === lastTxt && now - lastAt < 400) return; lastTxt = txt; lastAt = now;
        const bad = t.classList.contains('bad'); snd(bad ? 'error' : 'success', 300);
        // el centrado del aviso va por transform; acá se mueven translate y scale, que se suman
        anim(t, bad ? [{ translate: '0 0', scale: '.85' }, { translate: '-6px 0', scale: '1.04' }, { translate: '5px 0' }, { translate: '0 0', scale: '1' }]
          : [{ scale: '.7', opacity: 0 }, { scale: '1.05', opacity: 1, offset: .6 }, { scale: '1' }], { duration: 460, easing: bad ? 'ease-out' : SPRING });
      }).observe(t, { attributes: true, attributeFilter: ['hidden', 'class'], childList: true, characterData: true, subtree: true });
    };
    const dialog = d => {
      if (hooked.has(d)) return; hooked.add(d);
      new MutationObserver(() => { if (d.open) { snd('open', 200); stagger([...d.children].slice(0, 10), { step: 30, dy: 10, s: '1', d0: 80 }); } else snd('close', 200); })
        .observe(d, { attributes: true, attributeFilter: ['open'] });
    };
    // Profile Studio: su ventana es un <div hidden> con el contenido en .pf-dialog
    const pfModal = m => {
      if (hooked.has(m)) return; hooked.add(m);
      new MutationObserver(() => { if (!m.hidden) { snd('open', 200); const d = $('.pf-dialog', m);
          anim(d, [{ opacity: 0, scale: '.94', translate: '0 14px' }, { opacity: 1, scale: '1', translate: '0 0' }], { duration: 360, easing: SPRING });
          if (d) stagger([...d.children].slice(0, 8), { step: 30, dy: 10, s: '1', d0: 60 }); } else snd('close', 200); })
        .observe(m, { attributes: true, attributeFilter: ['hidden'] });
    };
    const scan = () => { $$('.pbe-toast, .pf-toast').forEach(toast); $$('dialog').forEach(dialog); $$('.pf-modal').forEach(pfModal); };
    scan(); new MutationObserver(scan).observe(document.body, { childList: true });
  }

  const start = () => { initNotices(); if (tool === 'profile') initProfile(); else if (document.body.classList.contains('pbe-list') || $('.pbl-h')) initList(); else initEditor(); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
