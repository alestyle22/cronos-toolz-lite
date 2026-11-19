/* Constructor de formularios · experiencia "juicy" (como las herramientas Globo / Calco / Rótulo)
   Se carga después de builder.js y envuelve sus funciones globales sin cambiarlas:
   - lo que entra al lienzo vuela desde la paleta y cae con rebote; lo que se mueve se desliza (FLIP);
     lo que se quita se encoge; al seleccionar, el campo late y las propiedades entran escalonadas;
   - sonidos de interfaz (los de g7-juice.js: solo si la persona prendió "Sonidos de la interfaz");
   - publicar festeja (lo hace g7-juice.js al ver la respuesta de form_publish).
   Movimiento: respeta html.g7-move (preferencia "Animaciones" y "reducir movimiento"). Doc: toolz/MOVIMIENTO.md */
(function(){
  if (typeof drawCanvas !== 'function' || typeof B === 'undefined') return;
  const moving = () => document.documentElement.classList.contains('g7-move');
  const snd = n => { try { window.G7Sound && G7Sound(n); } catch (e) {} };
  const EASE = 'cubic-bezier(.2,.8,.2,1)';
  let firstDraw = true, flyFrom = null, liveT = 0;

  // ── Lienzo: animar diferencias entre un dibujo y el siguiente ──
  const keyOf = el => el.matches('.bd-it') ? 'i:' + el.dataset.id : 's:' + el.dataset.sec;
  const _drawCanvas = drawCanvas;
  drawCanvas = function(){
    const P = document.getElementById('bd-paper'), before = new Map();
    if (P && !firstDraw) P.querySelectorAll('.bd-it[data-id], .bd-sec[data-sec]').forEach(el => before.set(keyOf(el), { r: el.getBoundingClientRect(), el }));
    const r = _drawCanvas.apply(this, arguments);
    if (firstDraw){ firstDraw = false; return r; }
    if (!moving() || !P) return r;
    const now = new Map(); P.querySelectorAll('.bd-it[data-id], .bd-sec[data-sec]').forEach(el => now.set(keyOf(el), el));
    let born = 0;
    now.forEach((el, k) => {
      const b = before.get(k), r2 = el.getBoundingClientRect();
      if (b){
        const dx = b.r.left - r2.left, dy = b.r.top - r2.top;
        if (Math.abs(dx) > 1 || Math.abs(dy) > 1) el.animate([{ translate: `${dx}px ${dy}px` }, { translate: '0 0' }], { duration: 420, easing: 'cubic-bezier(.3,1.25,.6,1)' });
      } else if (k[0] === 'i' && born < 6){
        born++;
        const land = () => el.animate([{ opacity: 0, scale: '.6', translate: '0 -18px' }, { opacity: 1, scale: '1.04', translate: '0 3px', offset: .6 }, { opacity: 1, scale: '1', translate: '0 0' }], { duration: 520, easing: EASE });
        if (flyFrom && born === 1) fly(flyFrom, el, land); else land();
      } else if (k[0] === 's') el.animate([{ opacity: 0, translate: '0 22px' }, { opacity: 1, translate: '0 0' }], { duration: 480, easing: EASE });
    });
    // lo que ya no está se encoge en su lugar (copia por encima)
    before.forEach((b, k) => {
      if (now.has(k) || k[0] !== 'i' || !b.el) return;
      const g = b.el.cloneNode(true); g.classList.add('bdj-gone');
      Object.assign(g.style, { position: 'fixed', left: b.r.left + 'px', top: b.r.top + 'px', width: b.r.width + 'px', height: b.r.height + 'px', margin: 0, zIndex: 60, pointerEvents: 'none' });
      document.body.appendChild(g);
      const a = g.animate([{ opacity: 1, scale: '1', rotate: '0deg' }, { opacity: 0, scale: '.7', rotate: '-3deg', translate: '0 12px' }], { duration: 320, easing: 'ease-in', fill: 'forwards' });
      a.onfinish = () => g.remove(); setTimeout(() => g.remove(), 500);
    });
    flyFrom = null;
    // si se está escribiendo en Propiedades, el campo del lienzo se ilumina: se ve qué se está cambiando
    const act = document.activeElement;
    if (act && act.closest && act.closest('#bd-props') && B.sel && B.sel.kind === 'item'){
      const el = P.querySelector(`.bd-it[data-id="${CSS.escape(B.sel.id)}"]`);
      if (el && performance.now() - liveT > 350){ liveT = performance.now(); el.classList.remove('bdj-live'); void el.offsetWidth; el.classList.add('bdj-live'); }
    }
    return r;
  };

  // Una pastilla vuela de la paleta al lugar donde cae el elemento
  function fly(from, el, then){
    const to = el.getBoundingClientRect(), chip = document.createElement('div'); chip.className = 'bd-ghost bdj-fly'; chip.innerHTML = from.html;
    document.body.appendChild(chip);
    const x0 = from.r.left, y0 = from.r.top, x1 = to.left + 14, y1 = to.top + 8, mx = (x0 + x1) / 2, my = Math.min(y0, y1) - 70;
    el.style.opacity = '0';
    const a = chip.animate([
      { transform: `translate(${x0}px,${y0}px) scale(1)`, opacity: 1 },
      { transform: `translate(${mx}px,${my}px) scale(1.12) rotate(-6deg)`, opacity: 1, offset: .5 },
      { transform: `translate(${x1}px,${y1}px) scale(.8)`, opacity: .2 }], { duration: 430, easing: 'cubic-bezier(.4,.1,.3,1)' });
    a.onfinish = () => { chip.remove(); el.style.opacity = ''; then(); burst(x1 + 10, y1 + 6); snd('pop'); };
    setTimeout(() => { if (chip.isConnected){ chip.remove(); el.style.opacity = ''; } }, 900);
  }
  function burst(x, y){
    for (let i = 0; i < 9; i++){ const s = document.createElement('i'); s.className = 'bdj-spark'; s.style.left = x + 'px'; s.style.top = y + 'px'; document.body.appendChild(s);
      const a = i / 9 * Math.PI * 2, v = 18 + Math.random() * 22;
      s.animate([{ transform: 'translate(0,0) scale(1)', opacity: 1 }, { transform: `translate(${Math.cos(a) * v}px,${Math.sin(a) * v}px) scale(.2)`, opacity: 0 }], { duration: 480, easing: EASE }).onfinish = () => s.remove(); }
  }

  // ── Acciones ──
  const _addItem = addItem;
  addItem = function(type, target){
    const btn = document.querySelector(`#bd-pal [data-type="${CSS.escape(type)}"]`);
    if (btn && !target){ const r = btn.getBoundingClientRect(); flyFrom = { r, html: btn.innerHTML }; btn.animate([{ scale: '1' }, { scale: '.86' }, { scale: '1.08' }, { scale: '1' }], { duration: 380, easing: 'ease-out' }); }
    else snd('drop');
    if (!moving()) snd('pop');
    return _addItem.apply(this, arguments);
  };
  const _addSection = addSection; addSection = function(){ snd('pop'); return _addSection.apply(this, arguments); };
  const _removeItem = removeItem; removeItem = function(){ snd('swoosh'); return _removeItem.apply(this, arguments); };
  const _dupItem = dupItem; dupItem = function(){ snd('pop'); return _dupItem.apply(this, arguments); };
  const _moveItem = moveItem; moveItem = function(){ snd('drop'); return _moveItem.apply(this, arguments); };
  const _drop = drop; drop = function(pl){ if (!pl.fresh) snd('drop'); return _drop.apply(this, arguments); };
  const _undo = undo; undo = function(){ if (B.hi > 0){ snd('rewind'); flash(); } return _undo.apply(this, arguments); };
  const _redo = redo; redo = function(){ if (B.hi < B.hist.length - 1){ snd('rewind'); flash(); } return _redo.apply(this, arguments); };
  function flash(){ const P = document.getElementById('bd-paper'); if (P && moving()) P.animate([{ filter: 'brightness(1.25)' }, { filter: 'brightness(1)' }], { duration: 300 }); }

  // Seleccionar: el campo late y las propiedades entran escalonadas
  const _select = select;
  select = function(s){
    const same = B.sel && s && B.sel.kind === s.kind && B.sel.id === s.id;
    const r = _select.apply(this, arguments);
    if (!same){ snd('tap');
      if (moving()){
        const el = s.kind === 'item' ? document.querySelector(`.bd-it[data-id="${CSS.escape(s.id)}"]`) : s.kind === 'sec' ? document.querySelector(`.bd-sec[data-sec="${CSS.escape(s.id)}"]`) : null;
        if (el) el.animate([{ scale: '1' }, { scale: s.kind === 'item' ? '1.02' : '1.005' }, { scale: '1' }], { duration: 320, easing: 'ease-out' });
        stagger();
      } }
    return r;
  };
  function stagger(){
    const P = document.getElementById('bd-props'); if (!P) return;
    [...P.children].slice(1, 14).forEach((c, i) => c.animate([{ opacity: 0, translate: '14px 0' }, { opacity: 1, translate: '0 0' }], { duration: 320, delay: i * 28, easing: EASE, fill: 'backwards' }));
  }

  // Al arrastrar: la pastilla se levanta (la crea builder.js; acá solo se detecta)
  new MutationObserver(list => { for (const m of list) for (const n of m.addedNodes) if (n.classList && n.classList.contains('bd-ghost') && !n.classList.contains('bdj-fly')) { snd('lift'); n.classList.add('bdj-lift'); } })
    .observe(document.body, { childList: true });

  // Guardado: el tilde del estado salta
  const st = document.getElementById('bd-status');
  if (st) new MutationObserver(() => { const i = st.querySelector('.fa-check'); if (i && moving() && !st.__ok){ st.__ok = true; i.animate([{ scale: '0', rotate: '-90deg' }, { scale: '1.4', rotate: '10deg', offset: .6 }, { scale: '1', rotate: '0deg' }], { duration: 480, easing: EASE }); } if (!i) st.__ok = false; })
    .observe(st, { childList: true, subtree: true });

  // Vista previa: entra con zoom
  const pv = document.getElementById('bd-prev');
  if (pv) new MutationObserver(() => { if (!pv.hidden){ snd('open'); if (moving()){ const d = document.getElementById('bd-device'); if (d) d.animate([{ opacity: 0, scale: '.9', translate: '0 30px' }, { opacity: 1, scale: '1', translate: '0 0' }], { duration: 460, easing: EASE }); } } else snd('close'); })
    .observe(pv, { attributes: true, attributeFilter: ['hidden'] });

  // Modalidad "Completo / Por pasos" y dispositivo de la vista previa: indicador que se desliza
  function slider(seg){
    const box = document.getElementById(seg); if (!box || box.querySelector('.bdj-ind')) return;
    const ind = document.createElement('span'); ind.className = 'bdj-ind'; box.prepend(ind);
    const place = () => { const on = box.querySelector('button.on'); if (!on){ ind.style.opacity = 0; return; } ind.style.opacity = 1; ind.style.left = on.offsetLeft + 'px'; ind.style.width = on.offsetWidth + 'px'; };
    new MutationObserver(place).observe(box, { attributes: true, subtree: true, attributeFilter: ['class'] });
    box.addEventListener('click', e => { if (e.target.closest('button')) snd('tap'); });
    requestAnimationFrame(place); setTimeout(place, 600);
  }
  slider('bd-mode'); slider('bd-dev');
})();
