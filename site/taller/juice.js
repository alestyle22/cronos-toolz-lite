/* Taller · sonidos de interfaz y efectos visuales comunes a todas las herramientas.
   Los sonidos se sintetizan con Web Audio (no hay archivos). Se silencian desde el menú lateral. */
(function(){
  if (window.__tallerJuice) return; window.__tallerJuice = true;
  const KEY = 'taller-sound';
  let on = true; try { on = localStorage.getItem(KEY) !== '0'; } catch (e) {}
  const VOL = .22;

  /* ============================== audio ============================== */
  let ac = null, master = null, noiseBuf = null;
  function ctx(){
    if (!ac){ const A = window.AudioContext || window.webkitAudioContext; if (!A) return null; ac = new A(); master = ac.createGain(); master.gain.value = VOL;
      const comp = ac.createDynamicsCompressor(); master.connect(comp); comp.connect(ac.destination);
      noiseBuf = ac.createBuffer(1, ac.sampleRate * .5, ac.sampleRate); const d = noiseBuf.getChannelData(0); for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1; }
    if (ac.state === 'suspended') ac.resume();
    return ac;
  }
  function env(g, t, a, peak, dur){ g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(peak, t + a); g.gain.exponentialRampToValueAtTime(0.0001, t + dur); }
  function tone(f, dur = .1, { type = 'sine', vol = .5, to = null, at = 0, a = .005 } = {}){
    const c = ctx(); if (!c) return; const t = c.currentTime + at, o = c.createOscillator(), g = c.createGain();
    o.type = type; o.frequency.setValueAtTime(f, t); if (to) o.frequency.exponentialRampToValueAtTime(to, t + dur * .9);
    env(g, t, a, vol, dur); o.connect(g); g.connect(master); o.start(t); o.stop(t + dur + .02);
  }
  function noise(dur = .1, { f = 2000, to = null, q = 1, vol = .4, at = 0, type = 'bandpass', a = .004 } = {}){
    const c = ctx(); if (!c) return; const t = c.currentTime + at, s = c.createBufferSource(), fl = c.createBiquadFilter(), g = c.createGain();
    s.buffer = noiseBuf; fl.type = type; fl.Q.value = q; fl.frequency.setValueAtTime(f, t); if (to) fl.frequency.exponentialRampToValueAtTime(to, t + dur);
    env(g, t, a, vol, dur); s.connect(fl); fl.connect(g); g.connect(master); s.start(t, Math.random() * .3); s.stop(t + dur + .02);
  }
  const rnd = (a, b) => a + Math.random() * (b - a);
  const SFX = {
    click: () => { noise(.025, { f: 3800, q: 2, vol: .35 }); tone(1900, .03, { vol: .12 }); },
    soft: () => { noise(.02, { f: 3000, q: 1.5, vol: .18 }); },
    pop: () => { tone(rnd(520, 600), .09, { to: 1300, vol: .42 }); noise(.03, { f: 5000, q: 3, vol: .12 }); },
    select: () => { tone(880, .06, { type: 'triangle', vol: .3 }); tone(1320, .08, { type: 'triangle', vol: .25, at: .045 }); },
    on: () => { tone(660, .07, { type: 'triangle', vol: .32 }); tone(990, .1, { type: 'triangle', vol: .3, at: .06 }); },
    off: () => { tone(880, .07, { type: 'triangle', vol: .28 }); tone(560, .1, { type: 'triangle', vol: .24, at: .06 }); },
    open: () => { noise(.16, { f: 600, to: 3200, q: 1.2, vol: .22 }); tone(420, .1, { to: 640, vol: .12 }); },
    close: () => { noise(.12, { f: 2600, to: 700, q: 1.2, vol: .16 }); },
    swish: () => { noise(.22, { f: 500, to: 4200, q: .9, vol: .3 }); },
    whoosh: () => { noise(.38, { f: 300, to: 2400, q: .7, vol: .35, a: .06 }); tone(180, .3, { to: 90, vol: .12, a: .04 }); },
    stop: () => { tone(500, .09, { to: 300, type: 'triangle', vol: .25 }); },
    success: () => { [1046.5, 1318.5, 1568, 2093].forEach((f, i) => tone(f, .32 - i * .03, { type: 'triangle', vol: .26, at: i * .065 })); noise(.25, { f: 7000, q: 1, vol: .06, at: .2 }); },
    notify: () => { tone(988, .1, { type: 'sine', vol: .28 }); tone(1480, .16, { type: 'sine', vol: .24, at: .08 }); },
    error: () => { tone(220, .12, { type: 'square', vol: .12, to: 180 }); tone(180, .16, { type: 'square', vol: .1, at: .13, to: 140 }); },
    rec: () => { tone(880, .07, { vol: .3 }); tone(880, .07, { vol: .3, at: .12 }); tone(1320, .12, { vol: .3, at: .24 }); },
    tick: p => { tone(900 + p * 1300, .025, { vol: .16 }); },
    key: () => { noise(.018, { f: rnd(2400, 4200), q: 3, vol: .12 }); },
    color: i => { const sc = [523, 587, 659, 784, 880, 1047, 1175, 1319, 1568, 1760]; tone(sc[i % sc.length], .12, { type: 'triangle', vol: .3 }); },
    grab: () => { tone(300, .06, { to: 420, vol: .2 }); },
    drop: () => { tone(260, .09, { to: 130, vol: .3 }); noise(.04, { f: 900, q: 1, vol: .12 }); },
    hover: () => { tone(rnd(1500, 1700), .02, { vol: .05 }); },
  };
  let last = {};
  function play(name, arg, gap = 0){ if (!on) return; const now = performance.now(); if (gap && now - (last[name] || 0) < gap) return; last[name] = now; try { SFX[name](arg); } catch (e) {} }
  window.TALLER_SFX = play;

  /* ============================== efectos visuales ============================== */
  const css = `
  .tl-ring{position:fixed;z-index:90;pointer-events:none;width:12px;height:12px;margin:-6px 0 0 -6px;border-radius:50%;border:2.5px solid var(--hi,#7C5CFF);animation:tlring .5s cubic-bezier(.2,.8,.3,1) forwards}
  @keyframes tlring{to{transform:scale(5.5);opacity:0;border-width:.5px}}
  .tl-spark{position:fixed;z-index:90;pointer-events:none;width:5px;height:5px;margin:-2.5px 0 0 -2.5px;border-radius:50%;background:var(--hi,#7C5CFF)}
  .tl-popv{animation:tlpopv .28s cubic-bezier(.34,1.56,.64,1)}
  @keyframes tlpopv{40%{transform:scale(1.35);color:var(--hi,#7C5CFF)}}
  .ib,.pill,.tg,.dd-btn,.dd-o,.cats button,.seg button,.gc,.sw .s,.dlw button,.tagItem,.emojis button,.pos button,.drop{will-change:transform}
  .ib:active,.pill:active,.tg:active,.dd-o:active,.cats button:active,.dlw button:active,.tagItem:active{transform:scale(.9)!important;transition-duration:.08s!important}
  .seg button:active{transform:scale(.88);transition:transform .08s}
  .sw .s:active{transform:scale(.8)!important}
  .gc{transform-style:preserve-3d;transition:transform .45s cubic-bezier(.34,1.56,.64,1),box-shadow .25s}
  .gc.tl-tilt{transition:transform .08s linear,box-shadow .25s;box-shadow:0 14px 34px rgba(0,0,0,.18)}
  .gc.tl-tilt canvas{transform:translateZ(30px)}
  .gc canvas{transition:transform .3s}
  .gc.tl-picked{animation:tlpick .5s cubic-bezier(.34,1.56,.64,1)}
  @keyframes tlpick{30%{transform:scale(.92) rotate(-2deg)}65%{transform:scale(1.05) rotate(1deg)}}
  .dd-pop.on .dd-o{animation:tlin .32s cubic-bezier(.34,1.56,.64,1) both}
  ${Array.from({ length: 14 }, (_, i) => `.dd-pop.on .dd-o:nth-of-type(${i + 1}){animation-delay:${i * 18}ms}`).join('')}
  @keyframes tlin{from{opacity:0;transform:translateX(-8px) scale(.96)}}
  input[type=range]{transition:filter .2s}
  input[type=range]:active{filter:drop-shadow(0 0 6px color-mix(in srgb,var(--hi,#7C5CFF) 60%,transparent))}
  .toast.on .ok{animation:tlok .5s cubic-bezier(.34,1.56,.64,1)}
  @keyframes tlok{0%{transform:scale(0) rotate(-90deg)}70%{transform:scale(1.25) rotate(8deg)}}
  .tl-snd{margin-top:auto;width:40px;height:40px;border-radius:12px;border:0;background:none;color:var(--muted,#7a7a86);display:grid;place-items:center;cursor:pointer;transition:transform .3s cubic-bezier(.34,1.56,.64,1),background .2s,color .2s}
  .tl-snd:hover{background:var(--soft,#f4f5f8);color:var(--ink,#141418);transform:rotate(-8deg)}
  .tl-snd svg{width:19px;height:19px}
  .tl-snd.off{color:#ef4444}
  @media (max-width:900px){.tl-snd{margin:0 0 0 auto}}
  @media (prefers-reduced-motion:reduce){.tl-ring,.tl-spark{display:none}.gc.tl-tilt{transform:none!important}}`;

  function ring(x, y, color){ const r = document.createElement('div'); r.className = 'tl-ring'; r.style.left = x + 'px'; r.style.top = y + 'px'; if (color) r.style.borderColor = color; document.body.appendChild(r); setTimeout(() => r.remove(), 520); }
  function sparks(x, y, n = 8, color){
    for (let i = 0; i < n; i++){ const s = document.createElement('div'); s.className = 'tl-spark'; s.style.left = x + 'px'; s.style.top = y + 'px'; if (color) s.style.background = color; document.body.appendChild(s);
      const a = i / n * Math.PI * 2 + Math.random() * .4, v = 22 + Math.random() * 26;
      s.animate([{ transform: 'translate(0,0) scale(1)', opacity: 1 }, { transform: `translate(${Math.cos(a) * v}px,${Math.sin(a) * v}px) scale(.2)`, opacity: 0 }], { duration: 420 + Math.random() * 200, easing: 'cubic-bezier(.2,.8,.3,1)' }).onfinish = () => s.remove(); }
  }

  /* ============================== qué suena con cada clic ============================== */
  function classify(el){
    if (!el) return null;
    if (el.closest('.tl-snd')) return null;
    if (el.closest('.tl-a, .tl-home, a.card')) return 'swish';
    if (el.closest('#dl, #copy')) return null;                    // suena el aviso de resultado
    if (el.closest('#vid')) return 'rec';
    if (el.closest('#play, #play2')) return 'play';
    if (el.closest('.dd-o')) return 'select';
    const dd = el.closest('.dd-btn'); if (dd) return dd.classList.contains('open') ? 'close' : 'open';
    if (el.closest('.gc')) return 'card';
    if (el.closest('.tg')) return 'toggle';
    if (el.closest('.sw .s')) return 'color';
    if (el.closest('.seg button, .cats button, .pos button')) return 'select';
    if (el.closest('.emojis button, .tagItem')) return 'pop';
    if (el.closest('.q')) return 'pop';
    if (el.closest('button, a, .drop')) return 'click';
    return null;
  }
  document.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    const kind = classify(e.target); if (!kind) return;
    ctx();
    if (kind === 'toggle'){ const t = e.target.closest('.tg'), was = t.classList.contains('on'); play(was ? 'off' : 'on'); if (!was) sparks(e.clientX, e.clientY, 6); }
    else if (kind === 'play'){ const b = e.target.closest('#play, #play2'); play(b.innerHTML.includes('square') ? 'stop' : 'whoosh'); }
    else if (kind === 'color'){ const s = e.target.closest('.sw .s'); play('color', [...s.parentNode.children].indexOf(s)); ring(e.clientX, e.clientY, getComputedStyle(s).backgroundColor); }
    else if (kind === 'card'){ const c = e.target.closest('.gc'); play('pop'); sparks(e.clientX, e.clientY, 10); c.classList.remove('tl-picked'); void c.offsetWidth; c.classList.add('tl-picked'); }
    else { play(kind); if (kind !== 'close') ring(e.clientX, e.clientY); }
  }, true);

  // sliders: un tic con tono según el valor
  document.addEventListener('input', e => {
    const el = e.target;
    if (el.type === 'range'){ const p = (el.value - el.min) / ((el.max - el.min) || 1); play('tick', p, 38);
      const v = el.parentNode && el.parentNode.querySelector('b'); if (v){ v.classList.remove('tl-popv'); void v.offsetWidth; v.classList.add('tl-popv'); } }
    else if (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && el.type === 'text')) play('key', null, 30);
  }, true);
  document.addEventListener('change', e => { if (e.target.type === 'file' && e.target.files && e.target.files.length) play('drop'); }, true);

  // arrastrar sobre el lienzo
  document.addEventListener('pointerdown', e => { if (e.target.id === 'stage' && e.button === 0){ const c = getComputedStyle(e.target).cursor; if (c === 'grab' || c === 'grabbing') play('grab'); } }, true);
  document.addEventListener('pointerup', e => { if (e.target.id === 'stage' && getComputedStyle(e.target).cursor.includes('grab')) play('drop'); }, true);

  // tarjetas de la galería: inclinación 3D siguiendo el mouse
  let tiltEl = null;
  document.addEventListener('pointermove', e => {
    const c = e.target.closest && e.target.closest('.gc');
    if (tiltEl && tiltEl !== c){ tiltEl.classList.remove('tl-tilt'); tiltEl.style.transform = ''; tiltEl = null; }
    if (!c || e.pointerType !== 'mouse') return;
    if (tiltEl !== c){ tiltEl = c; c.classList.add('tl-tilt'); play('hover', null, 90); }
    const r = c.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
    c.style.transform = `perspective(600px) rotateY(${x * 14}deg) rotateX(${-y * 14}deg) translateY(-3px) scale(1.03)`;
  }, { passive: true });
  document.addEventListener('pointerleave', () => { if (tiltEl){ tiltEl.classList.remove('tl-tilt'); tiltEl.style.transform = ''; tiltEl = null; } });

  // el aviso (toast) dice cómo salió: descargado, copiado, error
  function watchToast(){
    const t = document.getElementById('toast'); if (!t) return;
    new MutationObserver(() => {
      if (!t.classList.contains('on')) return;
      const ok = t.querySelector('.ok'), bg = ok ? ok.style.background || '' : '';
      if (/ef4444|239,\s*68,\s*68/i.test(bg)) play('error', null, 300);
      else if (/22c55e|34,\s*197,\s*94/i.test(bg)) play('success', null, 300);
      else play('notify', null, 300);
      const r = t.getBoundingClientRect(); if (r.width) sparks(r.left + 22, r.top + r.height / 2, 7);
    }).observe(t, { attributes: true, attributeFilter: ['class'], childList: true });
  }

  // botón de sonido en el menú lateral
  function addMute(){
    const rail = document.querySelector('.tl-rail'); if (!rail){ setTimeout(addMute, 60); return; }
    if (rail.querySelector('.tl-snd')) return;
    const b = document.createElement('button'); b.className = 'tl-snd'; b.type = 'button';
    const sync = () => { b.classList.toggle('off', !on); b.innerHTML = `<i data-lucide="${on ? 'volume-2' : 'volume-x'}"></i>`; b.title = on ? 'Silenciar sonidos' : 'Activar sonidos'; try { window.lucide && lucide.createIcons(); } catch (e) {} };
    b.onclick = () => { on = !on; try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) {} sync(); if (on){ ctx(); play('on'); } };
    rail.appendChild(b); sync();
  }

  function init(){ const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st); watchToast(); addMute(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
