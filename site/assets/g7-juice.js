/* Cronos · movimiento (etapa 1) y sonidos de interfaz (etapa 2)
   Doc: toolz/MOVIMIENTO.md

   Preferencias por persona (Mi configuración), que topbar.php pasa en el <script>:
     data-motion="1|0"  → "Animaciones" (dashboard_animations, prendido por defecto)
     data-sound="1|0"   → "Sonidos de la interfaz" (ui_sounds, APAGADO por defecto)
   localStorage g7-motion = 'off' | 'on' pisa la preferencia en esa computadora ('on' corre aunque el
   sistema pida reducir movimiento).

   Movimiento: Web Animations sobre la propiedad `scale` (se suma al transform de cada página).
   Sonido: Web Audio sintetizado, sin archivos, volumen bajo, y solo en acciones con resultado:
   guardar/crear (botón principal), avisos de éxito o error, abrir y cerrar ventanas, interruptores. */
(function(){
  if (window.__g7juice) return; window.__g7juice = true;
  const me = document.currentScript, ds = (me && me.dataset) || {};
  let pref = null; try { pref = localStorage.getItem('g7-motion'); } catch (e) {}
  const reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  const canMove = !!Element.prototype.animate && ('scale' in document.documentElement.style);
  let force = ds.force === '1', animOn = ds.motion !== '0';   // Mi configuración › Animaciones aunque el sistema las apague
  let motion = canMove && pref !== 'off' && (pref === 'on' || (animOn && (!reduce || force)));
  let sound = ds.sound === '1';
  const root = document.documentElement;
  root.classList.toggle('g7-move', motion);
  // Mi configuración avisa si el equipo tiene reducir movimiento
  if (reduce) document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.cfg-reduce-note').forEach(n => { n.hidden = false; }));

  /* ============================== sonidos ============================== */
  let ac = null, out = null, nbuf = null;
  function ctx(){
    if (!ac){ const A = window.AudioContext || window.webkitAudioContext; if (!A) return null;
      ac = new A(); out = ac.createGain(); out.gain.value = .16; const comp = ac.createDynamicsCompressor(); out.connect(comp); comp.connect(ac.destination);
      nbuf = ac.createBuffer(1, ac.sampleRate * .4, ac.sampleRate); const d = nbuf.getChannelData(0); for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1; }
    if (ac.state === 'suspended') ac.resume();
    return ac;
  }
  function env(g, t, a, peak, dur){ g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(peak, t + a); g.gain.exponentialRampToValueAtTime(.0001, t + dur); }
  function tone(f, dur, o = {}){
    const c = ctx(); if (!c) return; const t = c.currentTime + (o.at || 0), osc = c.createOscillator(), g = c.createGain();
    osc.type = o.type || 'sine'; osc.frequency.setValueAtTime(f, t); if (o.to) osc.frequency.exponentialRampToValueAtTime(o.to, t + dur * .9);
    env(g, t, o.a || .006, o.vol || .4, dur); osc.connect(g); g.connect(out); osc.start(t); osc.stop(t + dur + .02);
  }
  function hiss(dur, o = {}){
    const c = ctx(); if (!c) return; const t = c.currentTime + (o.at || 0), s = c.createBufferSource(), f = c.createBiquadFilter(), g = c.createGain();
    s.buffer = nbuf; f.type = 'bandpass'; f.Q.value = o.q || 1; f.frequency.setValueAtTime(o.f || 2000, t); if (o.to) f.frequency.exponentialRampToValueAtTime(o.to, t + dur);
    env(g, t, o.a || .005, o.vol || .3, dur); s.connect(f); f.connect(g); g.connect(out); s.start(t, Math.random() * .2); s.stop(t + dur + .02);
  }
  const SFX = {
    tap: () => { hiss(.025, { f: 3600, q: 2, vol: .28 }); tone(1600, .035, { vol: .1 }); },
    success: () => { [880, 1108.7, 1318.5].forEach((f, i) => tone(f, .24 - i * .03, { type: 'triangle', vol: .3, at: i * .07 })); },
    error: () => { tone(240, .12, { type: 'triangle', vol: .32, to: 200 }); tone(190, .18, { type: 'triangle', vol: .28, at: .12, to: 150 }); },
    open: () => { hiss(.14, { f: 700, to: 2800, q: 1.1, vol: .18 }); tone(520, .09, { to: 700, vol: .1 }); },
    close: () => { hiss(.1, { f: 2400, to: 800, q: 1.1, vol: .12 }); },
    on: () => { tone(660, .06, { type: 'triangle', vol: .26 }); tone(990, .09, { type: 'triangle', vol: .24, at: .055 }); },
    off: () => { tone(880, .06, { type: 'triangle', vol: .22 }); tone(587, .09, { type: 'triangle', vol: .2, at: .055 }); },
    // editores (constructor de formularios)
    pop: () => { tone(560, .09, { to: 1250, vol: .36 }); hiss(.03, { f: 5200, q: 3, vol: .1 }); },
    drop: () => { tone(300, .1, { to: 150, vol: .32 }); hiss(.04, { f: 900, q: 1, vol: .12 }); },
    lift: () => { tone(320, .08, { to: 480, vol: .2 }); },
    swoosh: () => { hiss(.2, { f: 3400, to: 500, q: .9, vol: .22 }); tone(500, .16, { to: 220, vol: .1 }); },
    rewind: () => { hiss(.18, { f: 900, to: 3000, q: 2.5, vol: .14 }); tone(700, .12, { to: 420, type: 'triangle', vol: .12 }); },
    // festejos (etapa 3)
    tick: () => { tone(1760, .05, { type: 'sine', vol: .14 }); tone(2637, .07, { type: 'sine', vol: .08, at: .03 }); },
    oops: () => { tone(330, .08, { type: 'triangle', vol: .2, to: 280 }); },
    thump: () => { tone(110, .38, { to: 38, vol: .55, a: .004 }); hiss(.18, { f: 260, q: .7, vol: .3 }); tone(880, .06, { type: 'triangle', vol: .08, at: .02 }); },
    chime: () => { tone(1568, .22, { type: 'sine', vol: .18 }); tone(2093, .3, { type: 'sine', vol: .14, at: .07 }); },
    pluck: () => { tone(1320, .12, { type: 'triangle', vol: .3, to: 1180 }); hiss(.03, { f: 6000, q: 2, vol: .08 }); },
    chord: () => { [784, 987.8, 1174.7].forEach((f, i) => tone(f, .32, { type: 'triangle', vol: .24, at: i * .05 })); hiss(.2, { f: 7000, q: 1, vol: .05, at: .1 }); },
    fanfare: () => { [523.3, 659.3, 784, 1046.5].forEach((f, i) => tone(f, .2, { type: 'triangle', vol: .3, at: i * .08 })); [1046.5, 1318.5, 1568].forEach(f => tone(f, .55, { type: 'sine', vol: .14, at: .34 })); hiss(.4, { f: 8000, q: .8, vol: .06, at: .32 }); },
    cash: () => { tone(2093, .09, { type: 'square', vol: .07 }); tone(2637, .5, { type: 'triangle', vol: .26, at: .07 }); tone(3136, .45, { type: 'sine', vol: .14, at: .12 }); hiss(.06, { f: 5000, q: 3, vol: .15 }); [523.3, 659.3, 784].forEach((f, i) => tone(f, .25, { type: 'triangle', vol: .18, at: .28 + i * .06 })); },
  };
  const last = {}; let quietUntil = 0;
  function play(name, gap = 120, force = false){
    if (!sound || document.hidden || !SFX[name]) return;
    const now = performance.now();
    if (!force && (now < quietUntil || now - (last.any || 0) < 60 || now - (last[name] || 0) < gap)) return; last.any = last[name] = now;
    if (force) quietUntil = now + 700;   // un festejo tapa el aviso que llega justo después
    try { SFX[name](); } catch (e) {}
    if (window.G7Juice) G7Juice.last = name;   // para revisar desde la consola qué sonó
  }
  window.G7Sound = play;

  /* ============================== qué responde a qué ============================== */
  const SPRING = 'cubic-bezier(.34,1.56,.64,1)';
  const PRESS = 'button, [role="button"], .g7-btn, a[class*="btn"], [class*="-btn"], .tc, .d3-tool, .d3-rc, .d3-wt, .d3-cl, [class*="chip"], [class*="pill"], [class*="tab"]:not([class*="table"])';
  const MAIN = '.g7-btn-primary, .btn-primary, .primary, .tc, .d3-tool, .d3-rc';
  const SAVE = '.g7-btn-primary, .btn-primary, button.primary, .primary[class*="btn"]';   // los que guardan o crean
  const BIG = '.tc, .d3-tool, .d3-rc, .d3-wt, .d3-cl, [class*="card"]';

  let pressed = null, pressAnim = null;
  function release(){
    if (!pressed) return; const el = pressed; pressed = null;
    const from = pressAnim ? getComputedStyle(el).scale : '1'; if (pressAnim){ pressAnim.cancel(); pressAnim = null; }
    el.animate([{ scale: from === 'none' ? '1' : from }, { scale: el.matches(BIG) ? '1.015' : '1.05' }, { scale: '1' }], { duration: 420, easing: 'ease-out' });
  }
  document.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    const el = e.target.closest && e.target.closest(PRESS);
    if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true' || el.closest('.g7-still-zone')) return;
    if (el.matches(SAVE)) play('tap');
    if (!motion) return;
    const r = el.getBoundingClientRect(); if (r.width > 700 || r.height > 260) return;   // nada de filas o paneles enteros
    pressed = el; const big = el.matches(BIG);
    pressAnim = el.animate([{ scale: '1' }, { scale: big ? '.985' : '.93' }], { duration: 90, easing: 'ease-out', fill: 'forwards' });
    if (el.matches(MAIN)){ const ring = document.createElement('div'); ring.className = 'g7j-ring'; ring.style.left = e.clientX + 'px'; ring.style.top = e.clientY + 'px';
      const bg = getComputedStyle(el).backgroundColor; if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) ring.style.borderColor = bg;
      document.body.appendChild(ring); setTimeout(() => ring.remove(), 520); }
  }, true);
  ['pointerup', 'pointercancel', 'dragstart'].forEach(t => document.addEventListener(t, release, true));
  window.addEventListener('blur', release);

  // Interruptores (checkbox dentro de un *toggle*, o role=switch)
  document.addEventListener('change', e => {
    const el = e.target; if (!el || el.type !== 'checkbox') return;
    if (!el.closest('[class*="toggle"], [class*="switch"], [role="switch"]')) return;
    const key = el.getAttribute('data-change-1');   // data-change-1 no pasa a camelCase en dataset
    if (key === 's:ui_sounds'){ sound = el.checked; if (sound) play('success'); return; }   // la propia preferencia, en vivo
    if (key === 's:dashboard_animations'){ animOn = el.checked; motion = el.checked && canMove && (!reduce || force || pref === 'on'); root.classList.toggle('g7-move', motion); }
    if (key === 's:motion_force'){ force = el.checked; motion = canMove && pref !== 'off' && (pref === 'on' || (animOn && (!reduce || force))); root.classList.toggle('g7-move', motion); }
    play(el.checked ? 'on' : 'off');
  }, true);

  // Ventanas y avisos de cualquier módulo
  const OPEN = /(^|\s)(open|show|on|visible|active)(\s|$)/;
  const isModal = el => /modal|overlay|dialog|dlg|drawer|popup|sheet|pal-bg/i.test(el.className) && !/toast/i.test(el.className);
  const isToast = el => /toast/i.test(el.className) && !/toasts/i.test(el.className);
  const isBad = el => /error|err\b|danger|fail|warn/i.test(el.className) || /^(error|no se pudo|falló|fallo)/i.test((el.textContent || '').trim());
  const seen = new WeakMap();
  function pop(el, kind){
    const now = performance.now(); if (now - (seen.get(el) || 0) < 400) return; seen.set(el, now);
    if (kind === 'toast'){
      setTimeout(() => play(isBad(el) ? 'error' : 'success', 300), 0);   // la clase de error a veces llega en el mismo paso
      if (motion) el.animate([{ scale: '.6' }, { scale: '1.06', offset: .6 }, { scale: '1' }], { duration: 480, easing: SPRING });
      return;
    }
    play('open', 200);
    if (!motion) return;
    let box = el; if (/overlay|bg|backdrop/i.test(el.className)){ const k = [...el.children].find(c => c.offsetWidth > 0); if (k) box = k; }
    if (box.getAnimations && box.getAnimations().length) return;   // ya tiene su propia entrada
    box.animate([{ opacity: 0, scale: '.92', translate: '0 14px' }, { opacity: 1, scale: '1.01', translate: '0 0', offset: .65 }, { opacity: 1, scale: '1', translate: '0 0' }], { duration: 420, easing: 'cubic-bezier(.2,.8,.2,1)' });
  }
  const mo = new MutationObserver(list => {
    for (const m of list){
      const el = m.target; if (!(el instanceof HTMLElement) || typeof el.className !== 'string') continue;
      const was = m.oldValue || '', now = el.className, o1 = OPEN.test(now), o0 = OPEN.test(was);
      if (o1 && !o0){ if (isToast(el)) pop(el, 'toast'); else if (isModal(el)) pop(el, 'modal'); }
      else if (!o1 && o0 && isModal(el)) play('close', 200);
    }
  });
  const start = () => mo.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'], attributeOldValue: true });
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);

  /* ============================== festejos (etapa 3) ============================== */
  let lastPt = null;
  document.addEventListener('pointerdown', e => { lastPt = { x: e.clientX, y: e.clientY, t: performance.now() }; }, true);
  const origin = () => lastPt && performance.now() - lastPt.t < 5000 ? lastPt : { x: innerWidth / 2, y: innerHeight * .35 };
  let fx = null, parts = [], raf = 0;
  function confetti(n, o, palette, opt = {}){
    if (!fx){ fx = document.createElement('canvas'); fx.className = 'g7j-fx'; document.body.appendChild(fx); }
    const dpr = Math.min(2, devicePixelRatio || 1); if (fx.width !== innerWidth * dpr){ fx.width = innerWidth * dpr; fx.height = innerHeight * dpr; }
    const cols = palette || [getComputedStyle(root).getPropertyValue('--g7-accent').trim() || '#ff6600', '#22c55e', '#3b82f6', '#f59e0b', '#ec4899', '#a855f7', '#ffffff'];
    const k = opt.power || 1, sz = opt.size || 1;
    for (let i = 0; i < n; i++){ const a = -Math.PI / 2 + (Math.random() - .5) * (opt.spread || 2.2), v = (5 + Math.random() * 9) * k;
      parts.push({ x: o.x, y: o.y, vx: Math.cos(a) * v, vy: Math.sin(a) * v - 2, g: .28 + Math.random() * .12, r: Math.random() * 6.3, vr: (Math.random() - .5) * .35, w: (5 + Math.random() * 6) * sz, h: (3 + Math.random() * 5) * sz, c: cols[i % cols.length], life: 1, round: Math.random() < .3 }); }
    if (!raf) raf = requestAnimationFrame(tick);
  }
  function tick(){
    const c = fx.getContext('2d'), dpr = fx.width / innerWidth; c.setTransform(dpr, 0, 0, dpr, 0, 0); c.clearRect(0, 0, innerWidth, innerHeight);
    parts = parts.filter(p => p.life > 0 && p.y < innerHeight + 40);
    for (const p of parts){ p.vy += p.g; p.vx *= .985; p.x += p.vx; p.y += p.vy; p.r += p.vr; p.life -= .006;
      c.save(); c.globalAlpha = Math.min(1, p.life * 2); c.translate(p.x, p.y); c.rotate(p.r); c.fillStyle = p.c;
      if (p.round){ c.beginPath(); c.arc(0, 0, p.w / 2.4, 0, Math.PI * 2); c.fill(); } else { c.scale(1, Math.cos(p.r * 2)); c.fillRect(-p.w / 2, -p.h / 2, p.w, p.h); } c.restore(); }
    if (parts.length) raf = requestAnimationFrame(tick); else { raf = 0; c.clearRect(0, 0, innerWidth, innerHeight); }
  }
  function chip(text, icon, o, color){
    const d = document.createElement('div'); d.className = 'g7j-chip'; if (color) d.style.setProperty('--c', color);
    d.innerHTML = `<i class="fa-solid ${icon}"></i><span></span>`; d.querySelector('span').textContent = text;
    document.body.appendChild(d); const w = d.offsetWidth;
    d.style.left = Math.max(10, Math.min(innerWidth - w - 10, o.x - w / 2)) + 'px'; d.style.top = Math.max(10, o.y - 64) + 'px';
    if (motion) d.animate([{ opacity: 0, scale: '.5', translate: '0 14px' }, { opacity: 1, scale: '1.08', translate: '0 -4px', offset: .5 }, { opacity: 1, scale: '1', translate: '0 -10px', offset: .8 }, { opacity: 0, scale: '.96', translate: '0 -28px' }], { duration: 1900, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'forwards' });
    else d.animate([{ opacity: 1 }, { opacity: 1, offset: .8 }, { opacity: 0 }], { duration: 1600, fill: 'forwards' });
    setTimeout(() => d.remove(), 2000);
  }
  // nivel: small (un paso), medium (todos los pasos, ticket), big (tarea), cash (factura pagada)
  function celebrate(level, text, icon){
    const o = origin(), GOLD = ['#f5c542', '#ffd76a', '#e8a317', '#fff1b3', '#22c55e'];
    if (level === 'small'){ play('pluck', 0, true); if (motion) confetti(10, o); return; }
    if (level === 'subtle'){ play('chime', 0, true); if (motion) confetti(16, o, null, { power: .55, size: .75, spread: 1.6 }); return; }
    if (level === 'medium'){ play('chord', 0, true); if (motion) confetti(36, o); if (text) chip(text, icon || 'fa-circle-check', o, '#22c55e'); return; }
    if (level === 'cash'){ play('cash', 0, true); if (motion){ confetti(70, o, GOLD); setTimeout(() => confetti(30, { x: o.x, y: o.y }, GOLD), 180); } if (text) chip(text, icon || 'fa-sack-dollar', o, '#e8a317'); return; }
    play('fanfare', 0, true); if (motion){ confetti(60, o); setTimeout(() => confetti(25, o), 160); } if (text) chip(text, icon || 'fa-trophy', o);
  }

  // Se detecta por las respuestas del servidor: no hace falta tocar cada módulo
  const taskDone = new Map();   // id → terminada (lo que se vio en listas y fichas)
  const noteTasks = j => { if (!j) return; for (const t of [].concat(j.tasks || [], j.task ? [j.task] : [])) if (t && t.id != null) taskDone.set(+t.id, !!t.completed_at); };
  function inspect(url, body, j){
    if (!j || j.error) return;
    if (/tareas_api\.php/.test(url)){
      const a = body.action;
      if ((a === 'save' || a === 'move') && body.id && body.status !== undefined && j.task){
        const id = +body.id, before = taskDone.get(id), now = !!j.task.completed_at;
        if (now && before === false) celebrate('big', '¡Tarea terminada!', 'fa-trophy');
        else if (now && before === undefined && Object.keys(body).length <= 5) celebrate('big', '¡Tarea terminada!', 'fa-trophy');
      }
      if (a === 'check_set' && body.done === true && j.task){
        const ch = j.task.checks || [], all = ch.length > 1 && ch.every(k => +k.done);
        if (all) celebrate('medium', '¡Todos los pasos listos!', 'fa-list-check'); else celebrate('small');
      }
      noteTasks(j); return;
    }
    if (/trabajo\/api\.php/.test(url)){
      if (body.action === 'ticket_update' && (body.status === 'resuelto' || body.status === 'cerrado') && j.ok !== false) celebrate('subtle');
      if (body.action === 'form_save' && !+body.id && j.id) celebrate('big', '¡Formulario creado!', 'fa-wand-magic-sparkles');
      return;
    }
    if (/\/promos\/(index\.php)?(\?|$)/.test(url) && body.action === 'save' && j.status === 'success' && j.item){
      celebrate('big', '¡Contenido cargado!', 'fa-cloud-arrow-up'); return;
    }
    if (/portal_api\.php/.test(url) && body.action === 'submit' && (j.code || j.id)){ celebrate('big'); return; }   // la pantalla ya dice "¡Pedido enviado!"
    if (/trabajo\/api\.php/.test(url) && body.action === 'form_submit' && j.id){ celebrate('big', '¡Pedido enviado!', 'fa-paper-plane'); return; }
    if (/portal_api\.php/.test(url) && body.action === 'form_publish' && j.version){
      if (!j.same) celebrate('big', '¡Formulario publicado! Versión ' + j.version, 'fa-rocket'); return;
    }
    if (/facturacion\/api\.php/.test(url) && body.action === 'pay' && j.invoice){
      if (j.invoice.status === 'pagada') celebrate('cash', '¡Factura pagada!'); else celebrate('medium', 'Pago registrado', 'fa-coins');
    }
  }
  const WATCH = /portal_api\.php|tareas_api\.php|trabajo\/api\.php|facturacion\/api\.php|\/promos\/(index\.php)?(\?|$)/;
  function bodyOf(b){
    if (typeof b === 'string' && b[0] === '{') return JSON.parse(b);
    if (typeof FormData !== 'undefined' && b instanceof FormData){ const o = {}; for (const [k, v] of b.entries()) if (typeof v === 'string') o[k] = v; return o; }
    return null;
  }
  if (window.fetch){
    const ofetch = window.fetch;
    window.fetch = function(input, init){
      const p = ofetch.apply(this, arguments);
      try { loadTrack(typeof input === 'string' ? input : (input && input.url) || '', p, init); } catch (e) {}
      try { btnTrack(typeof input === 'string' ? input : (input && input.url) || '', p); } catch (e) {}
      try {
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        const isChat = /(^|\/)chat\/api\.php/.test(url) || (url === 'api.php' && location.pathname.indexOf('/chat/') >= 0);
        if (isChat && init){ const cb = bodyOf(init.body); if (cb && /^(send|ticket_from_msg|task_from_msg)$/.test(cb.action)) p.then(r => r.clone().json()).then(jj => chatInspect(cb, jj)).catch(() => {}); }
        const body = WATCH.test(url) && init ? bodyOf(init.body) : null;
        if (body && body.action){
          p.then(r => r.clone().json()).then(j => inspect(url, body, j)).catch(() => {});
        }
      } catch (e) {}
      return p;
    };
  }

  /* ============================== carga: barra arriba, "Cargando…" animado y entrada escalonada ==============================
     La barra sale al navegar entre páginas y cuando una sección pide datos al servidor por algo que hizo la persona
     (clic, tecla o cambio de #ruta en los 1,5 s previos) o al abrir la página. Los pedidos que se repiten solos
     (avisos, zumbidos, chat, presencia) no la muestran. */
  const POLL = /notif\.php|ping\.php|chat\/api\.php|widget|heartbeat|presence|mood_api|g7-time|csp_report|keepalive|online/i;
  let gestureAt = -1e9, inflight = 0, bar = null, barP = 0, barTimer = 0, barShowT = 0, lastDone = 0, pill = null, pillT = 0;
  // Solo cuenta NAVEGAR (cambiar de sección o de página): después de una acción (cambiar un estado, mover en un tablero)
  // el módulo redibuja la lista y eso no tiene que parecer una recarga (pedido del usuario 2026-11-14)
  window.addEventListener('hashchange', () => { gestureAt = performance.now(); });
  window.addEventListener('popstate', () => { gestureAt = performance.now(); });
  function barEl(){
    if (!bar){ bar = document.createElement('div'); bar.className = 'g7j-bar'; bar.innerHTML = '<i></i>'; (document.body || root).appendChild(bar); }
    return bar;
  }
  function barStart(){
    clearTimeout(barShowT); clearInterval(barTimer);
    const b = barEl(); if (barP >= 1 || !b.classList.contains('on')){ barP = 0; b.firstChild.style.transition = 'none'; b.firstChild.style.transform = 'scaleX(0)'; void b.offsetWidth; b.firstChild.style.transition = ''; }
    b.classList.add('on'); barSet(Math.max(barP, .12));
    barTimer = setInterval(() => barSet(barP + (0.92 - barP) * .07), 180);   // avanza cada vez más lento, nunca llega sola al final
    clearTimeout(pillT); pillT = setTimeout(showPill, 1600);
  }
  function barSet(v){ barP = Math.min(.985, v); if (bar) bar.firstChild.style.transform = 'scaleX(' + barP + ')'; }
  function barDone(){
    clearInterval(barTimer); clearTimeout(pillT); hidePill(); if (!bar || !bar.classList.contains('on')) return;
    barP = 1; bar.firstChild.style.transform = 'scaleX(1)';
    setTimeout(() => { if (inflight === 0 && bar) bar.classList.remove('on'); }, 260);
  }
  function showPill(){
    if (pill || !document.body) return;
    pill = document.createElement('div'); pill.className = 'g7j-pill'; pill.innerHTML = '<span class="g7j-spin"></span>Cargando<span class="g7j-ell"><i>.</i><i>.</i><i>.</i></span>';
    document.body.appendChild(pill);
  }
  function hidePill(){ if (!pill) return; const el = pill; pill = null; el.classList.add('out'); setTimeout(() => el.remove(), 250); }
  function loadTrack(url, prom, init){
    if (POLL.test(url) || (init && init.keepalive)) return;
    const now = performance.now(); if (now - gestureAt > 1500 && document.readyState === 'complete' && now > 4000) return;   // solo al abrir la página o al cambiar de sección
    inflight++;
    clearTimeout(barShowT); barShowT = setTimeout(() => { if (inflight > 0) barStart(); }, 180);   // lo que responde al toque no muestra nada
    const end = () => { inflight = Math.max(0, inflight - 1); if (!inflight){ clearTimeout(barShowT); lastDone = performance.now(); barDone(); } };
    prom.then(end, end);
  }
  // Navegar a otra página: la barra arranca al irse y la página nueva la termina al cargar
  document.addEventListener('click', e => {
    const a = e.target.closest && e.target.closest('a[href]'); if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
    if (a.target && a.target !== '_self') return; if (a.hasAttribute('download')) return;
    const u = new URL(a.href, location.href); if (u.origin !== location.origin || (u.pathname === location.pathname && u.search === location.search)) return;
    setTimeout(() => { if (!e.defaultPrevented) barStart(); }, 0);
  });
  window.addEventListener('pageshow', () => { inflight = 0; barDone(); });
  if (document.readyState !== 'complete'){ barStart(); window.addEventListener('load', () => { if (!inflight) barDone(); }); }

  // "Cargando…" de cualquier módulo: rueda que gira y puntitos
  const LOADRX = /^\s*Cargando(…|\.\.\.)?\s*$/i;
  function decorateLoading(node){
    const scan = el => { if (el.nodeType !== 1 || el.classList.contains('g7j-loadtxt')) return; if (el.children.length === 0 && LOADRX.test(el.textContent || '')) el.classList.add('g7j-loadtxt'); };
    if (node.nodeType !== 1 || (node.textContent || '').indexOf('Cargando') < 0) return; scan(node);   // barato: solo si dice Cargando
    if (node.querySelectorAll) node.querySelectorAll('div, span, p, small, td, li, em, i, b').forEach(el => { if (el.children.length === 0 && LOADRX.test(el.textContent || '')) el.classList.add('g7j-loadtxt'); });
  }

  // Lo que llega después de cargar entra escalonado (listas, tarjetas, filas)
  const NOSTAG = '#ccw-msgs, #cc-msgs, #bd-paper, #fm-canvas, .dd-pop, [class*="dropdown"], [class*="menu"], .g7-topbar, #g7-topbar, header, nav, .g7j-stack, .toast, [class*="toast"], select, svg';
  function stagger(container){
    if (!motion || !container || container.closest(NOSTAG)) return;
    const kids = [...container.children].filter(k => k.nodeType === 1 && !/^(SCRIPT|STYLE|TEMPLATE|LINK)$/.test(k.tagName) && k.offsetHeight > 0);
    if (kids.length < 3) return;
    kids.slice(0, 16).forEach((k, i) => { if (k.getAnimations && k.getAnimations().length) return;
      k.animate([{ opacity: 0, translate: '0 10px' }, { opacity: 1, translate: '0 0' }], { duration: 380, delay: i * 32, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }); });
  }
  new MutationObserver(list => {
    const fresh = performance.now() - lastDone < 450 || document.readyState !== 'complete';
    const done = new Set();
    for (const m of list){
      // un "Cargando…" que ya se llenó con el contenido real deja de ser rueda (si no, su display:flex desarma lo que se dibuje adentro)
      const tg = m.target; if (tg && tg.classList && tg.classList.contains('g7j-loadtxt') && (tg.children.length || !LOADRX.test(tg.textContent || ''))) tg.classList.remove('g7j-loadtxt');
      for (const n of m.addedNodes) if (n.nodeType === 1) decorateLoading(n);
      if (!fresh || m.type !== 'childList' || m.addedNodes.length < 3 || done.has(m.target)) continue;
      done.add(m.target); stagger(m.target);
      // también la primera lista grande adentro de lo que llegó (p. ej. #wk-main → .wk-forms)
      for (const n of m.addedNodes) if (n.nodeType === 1 && n.querySelector){ const inner = [...n.querySelectorAll('ul, ol, tbody, [class*="list"], [class*="grid"], [class*="cards"], [class*="forms"]')].find(x => x.children.length >= 3); if (inner && !done.has(inner)){ done.add(inner); stagger(inner); } }
    }
  }).observe(document.documentElement, { childList: true, subtree: true });
  if (document.body) decorateLoading(document.body);

  /* ============================== llenar formularios ==============================
     Portal de Solicitudes (form_render.js: [data-fid], .ptf-opt, .ptf-cat, .bad) y cualquier formulario con
     validación del navegador. Elegir rebota, completar un obligatorio pone un tilde, un error tiembla, una barra
     muestra cuánto falta de lo obligatorio y festeja al completarlo. */
  const FIELD = '[data-fid]';
  const isReq = f => !!f.querySelector(':scope > .ptf-l .req, :scope > label .req');
  const filled = f => {
    if (f.querySelector('.ptf-opt.on, .ptf-cat.on, .on[data-cat]')) return true;
    for (const el of f.querySelectorAll('input, textarea, select')){
      if (el.type === 'hidden' || el.disabled || el.readOnly && !el.classList.contains('ptf-title')) continue;
      if ((el.type === 'checkbox' || el.type === 'radio') ? el.checked : String(el.value || '').trim() !== '') return true;
    }
    return false;
  };
  const shake = el => { if (!motion || !el) return; el.animate([{ translate: '0 0' }, { translate: '-7px 0' }, { translate: '6px 0' }, { translate: '-4px 0' }, { translate: '3px 0' }, { translate: '0 0' }], { duration: 420, easing: 'ease-out' }); };
  function markDone(f, quiet){
    const ok = isReq(f) && filled(f) && !f.classList.contains('bad');
    if (ok && !f.classList.contains('g7j-done')){ f.classList.add('g7j-done'); if (!quiet) play('tick', 150); }
    else if (!ok) f.classList.remove('g7j-done');
  }
  // barra de avance de lo obligatorio, arriba del formulario
  function progress(form, quiet){
    if (!form) return;
    const req = [...form.querySelectorAll(FIELD)].filter(f => !f.hidden && f.offsetParent !== null && isReq(f));
    let bar = form.querySelector(':scope > .g7j-fprog');
    if (req.length < 2){ if (bar) bar.remove(); return; }
    const n = req.filter(f => filled(f) && !f.classList.contains('bad')).length, pct = Math.round(n * 100 / req.length);
    if (!bar){ bar = document.createElement('div'); bar.className = 'g7j-fprog'; bar.innerHTML = '<i></i><span></span>'; form.prepend(bar); }
    bar.querySelector('i').style.width = pct + '%';
    bar.querySelector('span').textContent = pct === 100 ? '¡Todo lo obligatorio está completo!' : `${n} de ${req.length} obligatorios`;
    const was = +(bar.dataset.p || 0); bar.dataset.p = pct; bar.classList.toggle('full', pct === 100);
    if (pct === 100 && was < 100 && !quiet){ play('chord', 400); if (motion){ const r = bar.getBoundingClientRect(); confetti(14, { x: r.left + r.width / 2, y: r.top + 4 }, null, { power: .5, size: .7, spread: 1.4 }); } }
  }
  const formOf = el => el && el.closest && (el.closest('.ptf') || el.closest('#pv-body, #pt-form, form'));
  let progT = 0;
  const progSoon = (form, quiet) => { clearTimeout(progT); progT = setTimeout(() => progress(form && form.querySelector(FIELD) ? (form.querySelector('.ptf-grid') ? form : form) : form, quiet), 60); };
  function rootOfFields(el){ const f = el.closest(FIELD); if (!f) return null; let r = f.parentElement; while (r && r.parentElement && !r.classList.contains('ptf') && r.id !== 'pv-body' && r.tagName !== 'FORM') r = r.parentElement;
    return r && r !== document.body && r !== document.documentElement ? r : null; }

  // elegir una opción (tarjetas del portal o casillas y radios comunes)
  document.addEventListener('click', e => {
    const opt = e.target.closest && e.target.closest('.ptf-opt, .ptf-cat'); if (!opt || opt.closest('#bd-paper')) return;
    setTimeout(() => {
      const on = opt.classList.contains('on') || !!opt.querySelector('input:checked');
      play(on ? 'on' : 'off', 60);
      if (motion){ opt.animate([{ scale: '1' }, { scale: on ? '.92' : '.96' }, { scale: on ? '1.05' : '1' }, { scale: '1' }], { duration: 380, easing: 'ease-out' });
        if (on){ const r = opt.getBoundingClientRect(), ring = document.createElement('div'); ring.className = 'g7j-ring'; ring.style.left = (r.left + r.width / 2) + 'px'; ring.style.top = (r.top + r.height / 2) + 'px'; document.body.appendChild(ring); setTimeout(() => ring.remove(), 520); } }
      const f = opt.closest(FIELD); if (f) markDone(f); const root = rootOfFields(opt); if (root) progSoon(root);
    }, 0);
  }, true);
  // escribir y salir de un campo
  document.addEventListener('focusout', e => { const f = e.target.closest && e.target.closest(FIELD); if (!f || f.closest('#bd-paper')) return; setTimeout(() => { markDone(f); const root = rootOfFields(f); if (root) progSoon(root); }, 0); }, true);
  document.addEventListener('input', e => { const f = e.target.closest && e.target.closest(FIELD); if (!f || f.closest('#bd-paper')) return; if (f.classList.contains('g7j-done') && !filled(f)) f.classList.remove('g7j-done'); const root = rootOfFields(f); if (root) progSoon(root, true); }, true);
  document.addEventListener('change', e => { const f = e.target.closest && e.target.closest(FIELD); if (!f || f.closest('#bd-paper')) return; setTimeout(() => { markDone(f); const root = rootOfFields(f); if (root) progSoon(root); }, 0); }, true);
  // validación del navegador en cualquier formulario
  let oopsAt = 0;
  document.addEventListener('invalid', e => { const el = e.target; shake(el.closest('.wk-f, .g7-field, label, [data-fid]') || el); if (performance.now() - oopsAt > 600){ oopsAt = performance.now(); play('oops', 300); } }, true);
  // errores del portal: el campo que pasa a .bad tiembla; el que se corrige se pone verde
  new MutationObserver(list => {
    let shook = 0;
    for (const m of list){
      const el = m.target; if (!(el instanceof HTMLElement) || !el.matches(FIELD) || el.closest('#bd-paper')) continue;
      const bad = el.classList.contains('bad'), was = /(^|\s)bad(\s|$)/.test(m.oldValue || '');
      if (bad && !was){ if (shook++ < 4) shake(el); el.classList.remove('g7j-done'); if (shook === 1 && performance.now() - oopsAt > 600){ oopsAt = performance.now(); play('oops', 300); } }
      else if (!bad && was){ el.classList.add('g7j-fixed'); setTimeout(() => el.classList.remove('g7j-fixed'), 900); markDone(el); }
    }
  }).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['class'], attributeOldValue: true });
  // formularios que aparecen (el portal los dibuja después de cargar): barra y tildes de lo ya lleno, sin sonido
  new MutationObserver(list => {
    for (const m of list) for (const n of m.addedNodes){
      if (n.nodeType !== 1 || !n.querySelector || n.closest('#bd-paper')) continue;
      const fs = n.matches(FIELD) ? [n] : [...n.querySelectorAll(FIELD)]; if (!fs.length) continue;
      fs.forEach(f => markDone(f, true)); const root = rootOfFields(fs[0]); if (root) progSoon(root, true);
    }
  }).observe(document.documentElement, { childList: true, subtree: true });
  // pasos del portal: la barra avanza desde donde estaba
  let lastProg = null;
  new MutationObserver(() => {
    const i = document.querySelector('.pt-prog > i'); if (!i || !motion) return;
    const w = i.getBoundingClientRect().width, host = i.parentElement.getBoundingClientRect().width;
    if (lastProg !== null && Math.abs(lastProg - w) > 2 && host) i.animate([{ width: lastProg + 'px' }, { width: w + 'px' }], { duration: 520, easing: 'cubic-bezier(.3,1.25,.6,1)' });
    if (lastProg !== null && w > lastProg) play('tick', 200);
    lastProg = w;
    document.querySelectorAll('.pt-steps li.done').forEach(li => { if (!li.__g7j){ li.__g7j = true; li.animate([{ scale: '.7' }, { scale: '1.1', offset: .6 }, { scale: '1' }], { duration: 420, easing: 'ease-out' }); } });
  }).observe(document.documentElement, { childList: true, subtree: true });

  /* ============================== botones que trabajan y formularios comunes ==============================
     - Un botón principal que guarda algo: si el pedido tarda, gira una rueda sobre el botón; si sale bien, destello
       verde con tilde; si falla, tiembla en rojo. Sin tocar su texto (cada módulo lo maneja a su manera).
     - Formularios con .form-group (Promos y otros): tilde en lo completado, rebote al elegir pestañas o botones,
       barra de "% completado" que avanza con rebote y festeja al 100 %.
     - G7Juice.fieldError(campo, mensaje): error dentro del formulario en vez de alert(). */
  const SAVEBTN = '.submit-btn, .g7-btn-primary, .btn-primary, button.primary, .primary[class*="btn"], .wk-btn.primary, .pt-btn.primary, .cc-btn.pri';
  let armed = null;
  document.addEventListener('click', e => { const b = e.target.closest && e.target.closest(SAVEBTN); if (b && !b.disabled) armed = { b, t: performance.now() }; }, true);
  function btnTrack(url, prom){
    if (!armed || performance.now() - armed.t > 400 || POLL.test(url)) return;
    const b = armed.b; armed = null; if (b.__g7jBusy) return; b.__g7jBusy = true;
    const busyT = setTimeout(() => { if (getComputedStyle(b).position === 'static') b.classList.add('g7j-rel'); b.classList.add('g7j-busy'); }, 260);
    const end = ok => {
      clearTimeout(busyT); b.__g7jBusy = false; b.classList.remove('g7j-busy');
      if (!b.isConnected) return;
      if (getComputedStyle(b).position === 'static') b.classList.add('g7j-rel');
      b.classList.remove('g7j-ok', 'g7j-bad'); void b.offsetWidth; b.classList.add(ok ? 'g7j-ok' : 'g7j-bad');
      if (!ok) shake(b);
      setTimeout(() => b.classList.remove('g7j-ok', 'g7j-bad', 'g7j-rel'), 1100);
    };
    prom.then(r => r.clone().text().then(t => { let j = null; try { j = JSON.parse(t); } catch (e) {} end(r.ok && !(j && (j.error || j.status === 'error' || j.ok === false))); }), () => end(false));
  }

  function fieldError(el, msg){
    if (!el) return;
    const box = el.closest('.form-group, .wk-f, .g7-field, [data-fid], label') || el.parentElement;
    box.classList.add('g7j-err');
    let m = box.querySelector(':scope > .g7j-errmsg'); if (!m){ m = document.createElement('div'); m.className = 'g7j-errmsg'; box.appendChild(m); }
    m.textContent = msg; shake(box); play('oops', 300);
    try { el.focus({ preventScroll: true }); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
    const clear = () => { box.classList.remove('g7j-err'); m.remove(); el.removeEventListener('input', clear); el.removeEventListener('change', clear); };
    el.addEventListener('input', clear); el.addEventListener('change', clear);
  }

  // campos de formularios comunes (.form-group): tilde al completar
  const FG = '.form-group';
  const fgFilled = g => [...g.querySelectorAll('input, select, textarea')].some(el => el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.type === 'file' ? el.files && el.files.length : String(el.value || '').trim() !== '' && el.value !== '0');
  function fgMark(g, quiet){
    if (!g || g.closest('.hidden') || g.offsetParent === null) return;
    const ok = fgFilled(g) && !g.classList.contains('g7j-err');
    if (ok && !g.classList.contains('g7j-done')){ g.classList.add('g7j-done'); if (!quiet) play('tick', 150); }
    else if (!ok) g.classList.remove('g7j-done');
  }
  document.addEventListener('focusout', e => { const g = e.target.closest && e.target.closest(FG); if (g) setTimeout(() => fgMark(g), 0); }, true);
  document.addEventListener('change', e => {
    const g = e.target.closest && e.target.closest(FG); if (!g) return;
    setTimeout(() => fgMark(g), 0);
    if (e.target.type === 'file' && e.target.files && e.target.files.length && motion) g.animate([{ scale: '1' }, { scale: '1.02' }, { scale: '1' }], { duration: 360, easing: 'ease-out' });
  }, true);
  // pestañas y botones que se marcan con .active (tipo de contenido, formato…)
  new MutationObserver(list => {
    for (const m of list){
      const el = m.target; if (!(el instanceof HTMLElement) || el.tagName !== 'BUTTON') continue;
      const on = el.classList.contains('active'), was = /(^|\s)active(\s|$)/.test(m.oldValue || '');
      if (on && !was && el.closest('.form-group, .type-tabs, [class*="toggle"], .fc-body')){
        if (motion) el.animate([{ scale: '.9' }, { scale: '1.06', offset: .6 }, { scale: '1' }], { duration: 360, easing: 'ease-out' });
        play('on', 120);
      }
    }
  }).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['class'], attributeOldValue: true });
  // barra de "% completado" de Promos: cada 25 % suena, al 100 % festeja
  let lastPct = null;
  new MutationObserver(() => {
    const lbl = document.getElementById('fc-prog-pct'); if (!lbl) return;
    const pct = parseInt(lbl.textContent, 10); if (isNaN(pct) || pct === lastPct) return;
    const prev = lastPct; lastPct = pct; if (prev === null) return;
    const fill = document.getElementById('fc-prog-fill');
    if (pct > prev && Math.floor(pct / 25) > Math.floor(prev / 25)) play('tick', 200);
    if (motion && fill && pct > prev) fill.animate([{ filter: 'brightness(1)' }, { filter: 'brightness(1.6)' }, { filter: 'brightness(1)' }], { duration: 500 });
    if (motion) lbl.animate([{ scale: '1' }, { scale: '1.25' }, { scale: '1' }], { duration: 320, easing: 'ease-out' });
    if (pct === 100 && prev < 100){ play('chord', 400); if (motion && fill){ const r = fill.getBoundingClientRect(); confetti(16, { x: r.right - 10, y: r.top }, null, { power: .5, size: .7, spread: 1.4 }); } }
  }).observe(document.documentElement, { childList: true, subtree: true, characterData: true });

  /* ============================== abrir una aplicación desde un acceso ==============================
     Accesos rápidos (.tc), "Sigue donde dejaste" (.d3-rc) y "Más herramientas" (.d3-tool): la tarjeta se agranda
     con su color hasta cubrir la pantalla y recién ahí se navega; la página nueva sigue la animación (g7-vt.js). */
  const TILE = 'a.tc, a.d3-rc, a.d3-tool';
  document.addEventListener('click', e => {
    const a = e.target.closest && e.target.closest(TILE);
    if (!a || !motion || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    if (a.target && a.target !== '_self') return;
    const u = new URL(a.href, location.href); if (u.origin !== location.origin) return;
    e.preventDefault();
    const r = a.getBoundingClientRect(), cs = getComputedStyle(a);
    const tc = (cs.getPropertyValue('--tc') || '').trim();
    const art = a.querySelector('.tc-art, .d3-ic'), artBg = art ? getComputedStyle(art).backgroundColor : '';
    const bg = tc || (artBg && !/rgba\(0, 0, 0, 0\)/.test(artBg) ? artBg : '') || cs.backgroundColor || 'var(--g7-accent)';
    const ic = a.querySelector('i.fa-solid, i.fa-regular, i.fa-brands'), icon = ic ? [...ic.classList].filter(k => /^fa/.test(k)).join(' ') : '';
    const fill = tc ? `radial-gradient(120% 120% at 30% 20%, color-mix(in srgb, ${tc} 85%, #fff), ${tc} 55%, color-mix(in srgb, ${tc} 70%, #000))` : bg;
    try { sessionStorage.setItem('g7-vt', JSON.stringify({ bg: fill, icon, t: Date.now() })); } catch (err) {}
    const ov = document.createElement('div'); ov.className = 'g7vt-cover g7vt-grow';
    ov.style.background = fill; if (icon){ const i = document.createElement('i'); i.className = icon; ov.appendChild(i); }
    document.documentElement.appendChild(ov);
    const W = innerWidth, H = innerHeight;
    play('open', 0, true);
    a.animate([{ scale: '1' }, { scale: '.94' }], { duration: 120, fill: 'forwards' });
    const anim = ov.animate([
      { clipPath: `inset(${r.top}px ${W - r.right}px ${H - r.bottom}px ${r.left}px round 16px)` },
      { clipPath: 'inset(0px 0px 0px 0px round 0px)' }], { duration: 420, easing: 'cubic-bezier(.7,0,.25,1)', fill: 'forwards' });
    ov.querySelector('i') && ov.querySelector('i').animate([{ scale: '.6', opacity: .4 }, { scale: '1.15', opacity: 1, offset: .7 }, { scale: '1', opacity: 1 }], { duration: 420, easing: 'ease-out', fill: 'forwards' });
    let gone = false; const nav = () => { if (gone) return; gone = true; location.href = a.href; };
    anim.onfinish = nav; setTimeout(nav, 600);
    // si la persona vuelve con "atrás", que no quede la tapa puesta
    window.addEventListener('pageshow', ev => { if (ev.persisted){ ov.remove(); a.getAnimations().forEach(x => x.cancel()); } }, { once: true });
  });

  /* ============================== la app que se abre: elementos en cascada ==============================
     Después de la transición de abrir una aplicación (g7-vt.js avisa con "g7vt:reveal"), cada elemento visible
     entra por su cuenta, en orden de lectura: títulos, tarjetas, accesos, filas, campos. Lo que la página termina
     de dibujar en el segundo y medio siguiente (módulos que cargan datos después) entra igual. */
  const ENTER_SEL = 'h1, h2, .wk-h, .d3-sec-head, [class*="card"], [class*="tile"], .tc, .d3-tool, .d3-rc, .d3-wt, .d3-stat, [class*="kpi"], [class*="item"], .wk-row, .tk-row, .tk-card, li, tr, .form-group, [data-fid], .g7-btn-primary, .wk-btn.primary';
  const ENTER_NO = '#g7-topbar, #ccw, #ccw-heads, #ccw-panel, .g7vt-cover, .g7j-bar, .g7j-pill, .g7j-stack, .dd-pop, [class*="dropdown"], [class*="menu"], [hidden], select, script, style';
  let enterUntil = 0, enterN = 0;
  function enterItems(scope){
    if (!motion) return;
    const vh = innerHeight, picked = [];
    for (const el of scope.querySelectorAll(ENTER_SEL)){
      if (el.closest(ENTER_NO) || el.__g7jIn) continue;
      if (el.children.length > 2 && /grid|list|cards|wrap|container|columns/i.test(typeof el.className === 'string' ? el.className : '')) continue;   // un contenedor: que entren sus tarjetas
      const r = el.getBoundingClientRect(); if (r.height < 10 || r.width < 30 || r.bottom < 0 || r.top > vh + 40) continue;
      if (picked.some(p => p.el.contains(el))) continue;   // solo el de afuera: una tarjeta entra entera
      picked.push({ el, r });
      if (picked.length >= 40) break;
    }
    picked.sort((a, b) => (Math.round(a.r.top / 24) - Math.round(b.r.top / 24)) || (a.r.left - b.r.left));
    picked.forEach(({ el }) => {
      el.__g7jIn = true; const i = enterN++;
      el.animate([{ opacity: 0, translate: '0 18px', scale: '.97' }, { opacity: 1, translate: '0 -2px', scale: '1.005', offset: .7 }, { opacity: 1, translate: '0 0', scale: '1' }],
        { duration: 520, delay: Math.min(i * 30, 650), easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' });
    });
  }
  window.addEventListener('g7vt:reveal', () => {
    enterN = 0; enterUntil = performance.now() + 1500;
    let ran = false; const run = () => { if (!ran){ ran = true; enterItems(document.body); } };
    requestAnimationFrame(run); setTimeout(run, 60);   // en una pestaña de fondo no hay cuadros: igual arranca
  });
  new MutationObserver(list => {
    if (performance.now() > enterUntil) return;
    for (const m of list) for (const n of m.addedNodes) if (n.nodeType === 1 && n.querySelectorAll && !n.closest(ENTER_NO)) enterItems(n.parentElement || n);
  }).observe(document.documentElement, { childList: true, subtree: true });

  /* ============================== menú de arriba ==============================
     Una píldora que se desliza siguiendo el mouse por las secciones y vuelve a la activa; el menú de la persona
     se despliega con sus opciones escalonadas; la paleta Ctrl+K muestra sus resultados en cascada al abrirse. */
  function navPill(){
    const nav = document.getElementById('g7-nav'); if (!nav || nav.__g7j) return; nav.__g7j = true;
    const pill = document.createElement('span'); pill.className = 'g7j-navpill'; nav.prepend(pill);
    // ojo: no tocar la posición del menú (el centrado usa position:absolute); la píldora se ubica respecto de su contenedor real
    const place = (el, instant) => {
      if (!el){ pill.style.opacity = 0; return; }
      const r = el.getBoundingClientRect(), op = pill.offsetParent || nav, n = op.getBoundingClientRect();
      if (instant) pill.style.transition = 'none';
      Object.assign(pill.style, { opacity: 1, left: (r.left - n.left + op.scrollLeft) + 'px', top: (r.top - n.top + op.scrollTop) + 'px', width: r.width + 'px', height: r.height + 'px' });
      if (instant){ void pill.offsetWidth; pill.style.transition = ''; }
    };
    const active = () => nav.querySelector('.g7-link.active');
    nav.addEventListener('pointerover', e => { const l = e.target.closest('.g7-link'); if (l && nav.contains(l)){ place(l); nav.classList.add('g7j-hov'); } });
    nav.addEventListener('pointerleave', () => { nav.classList.remove('g7j-hov'); place(active()); });
    requestAnimationFrame(() => place(active(), true)); setTimeout(() => place(active(), true), 500);
    window.addEventListener('resize', () => place(active(), true));
  }
  new MutationObserver(list => {
    for (const m of list){
      const el = m.target; if (!(el instanceof HTMLElement)) continue;
      const on = el.classList.contains('open'), was = /(^|\s)open(\s|$)/.test(m.oldValue || '');
      if (el.classList.contains('g7-um') && on !== was){
        play(on ? 'open' : 'close', 150);
        if (on && motion) el.querySelectorAll('.g7-um-head, .g7-um-item, .g7-um-sep').forEach((it, i) => it.animate([{ opacity: 0, translate: '10px 0' }, { opacity: 1, translate: '0 0' }], { duration: 300, delay: 40 + i * 28, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
      }
      if (el.classList.contains('g7-pal-bg') && on && !was && motion){
        setTimeout(() => { const l = document.getElementById('g7-pal-list'); if (l) [...l.children].slice(0, 12).forEach((it, i) => it.animate([{ opacity: 0, translate: '0 8px' }, { opacity: 1, translate: '0 0' }], { duration: 280, delay: i * 22, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' })); }, 20);
      }
      if (el.id === 'g7-mobile-nav' && on && !was && motion) el.querySelectorAll('.g7-link').forEach((it, i) => it.animate([{ opacity: 0, translate: '-14px 0' }, { opacity: 1, translate: '0 0' }], { duration: 320, delay: i * 30, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
    }
  }).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['class'], attributeOldValue: true });
  // los contadores del menú (Monday, avisos) saltan cuando cambian
  new MutationObserver(list => { for (const m of list){ const b = m.target.nodeType === 3 ? m.target.parentElement : m.target; if (b && b.classList && b.classList.contains('g7-nav-badge') && motion) b.animate([{ scale: '.5' }, { scale: '1.35', offset: .5 }, { scale: '1' }], { duration: 420, easing: SPRING }); } })
    .observe(document.documentElement, { subtree: true, childList: true, characterData: true });
  if (document.readyState !== 'loading') navPill(); else document.addEventListener('DOMContentLoaded', navPill);

  /* ============================== chat: emojis, contenidos y tickets ============================== */
  const SEGM = typeof Intl.Segmenter === 'function' ? new Intl.Segmenter('es', { granularity: 'grapheme' }) : null;
  const EMOJI_RX = /\p{Extended_Pictographic}/u;
  const emojisOf = t => (SEGM ? [...SEGM.segment(t)].map(x => x.segment) : [...t]).filter(g => EMOJI_RX.test(g));
  const onlyEmojis = t => !!t && t.trim().length <= 24 && /^(\p{Extended_Pictographic}|\p{Emoji_Component}|‍|️|\s)+$/u.test(t.trim()) && !/^[\d#*\s]+$/.test(t.trim());
  function composerPt(){
    if (lastPt && performance.now() - lastPt.t < 3000) return lastPt;
    const ta = document.getElementById('cc-ta') || document.getElementById('ccw-ta');
    if (ta){ const r = ta.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top }; }
    return { x: innerWidth / 2, y: innerHeight * .7 };
  }
  function emojiBurst(list, o, n = 10, big = 1){
    if (!motion || !list.length) return;
    for (let i = 0; i < n; i++){
      const s = document.createElement('span'); s.className = 'g7j-emo'; s.textContent = list[i % list.length];
      s.style.left = o.x + 'px'; s.style.top = o.y + 'px'; s.style.fontSize = (22 + Math.random() * 20) * big + 'px'; document.body.appendChild(s);
      const dx = (Math.random() - .5) * 220, dy = -(120 + Math.random() * 220), rot = (Math.random() - .5) * 70;
      s.animate([{ transform: 'translate(-50%,-50%) scale(.2)', opacity: 0 }, { transform: `translate(calc(-50% + ${dx * .35}px), calc(-50% + ${dy * .4}px)) scale(1.15) rotate(${rot * .4}deg)`, opacity: 1, offset: .25 },
        { transform: `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px)) scale(.9) rotate(${rot}deg)`, opacity: 0 }],
        { duration: 1000 + Math.random() * 500, delay: i * 35, easing: 'cubic-bezier(.2,.7,.3,1)', fill: 'backwards' }).onfinish = () => s.remove();
      setTimeout(() => s.remove(), 2200);
    }
  }
  function flyChip(html, o, color){
    if (!motion) return;
    const box = document.getElementById('cc-msgs') || document.getElementById('ccw-msgs');
    const tr = box ? box.getBoundingClientRect() : { left: innerWidth / 2, top: innerHeight / 3, width: 0, height: 0 };
    const c = document.createElement('div'); c.className = 'g7j-flychip'; c.innerHTML = html; if (color) c.style.borderColor = color; document.body.appendChild(c);
    const x1 = tr.left + tr.width * .6, y1 = tr.top + tr.height - 60;
    c.animate([{ transform: `translate(${o.x}px,${o.y}px) scale(.7)`, opacity: 0 }, { transform: `translate(${(o.x + x1) / 2}px,${Math.min(o.y, y1) - 60}px) scale(1.1) rotate(-5deg)`, opacity: 1, offset: .45 },
      { transform: `translate(${x1}px,${y1}px) scale(.9)`, opacity: 0 }], { duration: 650, easing: 'cubic-bezier(.4,.1,.3,1)' }).onfinish = () => c.remove();
    setTimeout(() => c.remove(), 900);
  }
  const REF_ICON = { cliente: 'fa-address-book', contenido: 'fa-layer-group', tarea: 'fa-list-check', ticket: 'fa-ticket', jira: 'fa-jira' };
  function chatInspect(body, j){
    if (!j || j.ok === false || j.error) return;
    const a = body.action;
    if (a === 'send'){
      const t = String(body.body || ''), o = composerPt();
      if (onlyEmojis(t)){ const em = emojisOf(t); emojiBurst(em, o, em.length === 1 ? 12 : 14, em.length === 1 ? 1.25 : 1); play('pop', 0, true); return; }
      const refs = [...t.matchAll(/\[\[(cliente|contenido|tarea|ticket|jira):[^\]]+\]\]/g)].map(m => m[1]);
      if (refs.length){ const k = refs[0]; flyChip(`<i class="fa-solid ${REF_ICON[k] || 'fa-link'}"></i> ${k === 'contenido' ? 'Contenido' : k[0].toUpperCase() + k.slice(1)} enviado`, o); play('swoosh', 0, true); setTimeout(() => play('tick', 0, true), 380); return; }
      if ((body.files || []).length){ flyChip(`<i class="fa-solid fa-paper-plane"></i> ${body.files.length === 1 ? 'Archivo enviado' : body.files.length + ' archivos enviados'}`, o); play('swoosh', 0, true); return; }
      const em = emojisOf(t); if (em.length) emojiBurst(em, o, 4, .8);   // un mensaje con emojis: unos poquitos
      return;
    }
    if (a === 'ticket_from_msg'){ celebrate('medium', 'Ticket creado desde el chat', 'fa-ticket'); return; }
    if (a === 'task_from_msg'){ celebrate('medium', 'Tarea creada desde el chat', 'fa-list-check'); return; }
  }
  // reaccionar: el emoji salta desde el botón
  document.addEventListener('click', e => {
    const b = e.target.closest && e.target.closest('[data-react]'); if (!b || !(b.closest('#cc-msgs, #cc-tmsgs, #ccw-msgs, .cc-acts, .cc-reacts'))) return;
    const r = b.getBoundingClientRect(); emojiBurst([b.dataset.react], { x: r.left + r.width / 2, y: r.top + r.height / 2 }, 5, .8); play('pop', 80);
    if (motion) b.animate([{ scale: '1' }, { scale: '1.5' }, { scale: '1' }], { duration: 380, easing: SPRING });
  }, true);

  /* ============================== emoji inflable ==============================
     Mantener apretado un emoji del selector (chat completo .cc-emo [data-e], burbuja y chat .cc-pk [data-em]):
     se infla con un sonido que sube; al soltar se manda grande. El tamaño (1 a 3) viaja en el mensaje como
     separadores invisibles U+2063 al final, así no hace falta tocar la base. Quien lo recibe lo ve caer con impacto. */
  const HUGE = '⁣';
  let lastTA = null;
  document.addEventListener('focusin', e => { if (e.target && /^(cc-ta|ccw-ta)$/.test(e.target.id)) lastTA = e.target; }, true);
  let hold = null;
  function inflateTone(){
    const c = ctx(); if (!c || !sound) return null;
    const o = c.createOscillator(), lfo = c.createOscillator(), lg = c.createGain(), g = c.createGain(), t = c.currentTime;
    o.type = 'triangle'; o.frequency.setValueAtTime(220, t); o.frequency.exponentialRampToValueAtTime(900, t + 1.8);
    lfo.frequency.setValueAtTime(5, t); lfo.frequency.linearRampToValueAtTime(14, t + 1.8); lg.gain.setValueAtTime(6, t); lg.gain.linearRampToValueAtTime(30, t + 1.8);
    lfo.connect(lg); lg.connect(o.frequency);
    g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(.12, t + .12);
    o.connect(g); g.connect(out); o.start(t); lfo.start(t);
    return { stop(){ const n = c.currentTime; g.gain.cancelScheduledValues(n); g.gain.setValueAtTime(g.gain.value, n); g.gain.exponentialRampToValueAtTime(.0001, n + .08); o.stop(n + .1); lfo.stop(n + .1); } };
  }
  const levelOf = ms => ms < 700 ? 1 : ms < 1400 ? 2 : 3;
  document.addEventListener('pointerdown', e => {
    const b = e.target.closest && e.target.closest('.cc-emo [data-e], .cc-pk [data-em]'); if (!b || e.button !== 0) return;
    const em = b.dataset.e || b.dataset.em; if (!em) return;
    hold = { b, em, x: e.clientX, y: e.clientY, t0: performance.now(), on: false, el: null, snd: null, raf: 0 };
    hold.timer = setTimeout(() => {
      if (!hold) return; hold.on = true;
      const f = document.createElement('div'); f.className = 'g7j-inflate'; f.textContent = em; f.style.left = hold.x + 'px'; f.style.top = hold.y + 'px'; document.body.appendChild(f); hold.el = f;
      hold.snd = inflateTone();
      const grow = () => { if (!hold || !hold.on) return; const s = Math.min(1, (performance.now() - hold.t0 - 220) / 1600);
        const k = 1.4 + s * 4.6, w = s > .95 ? Math.sin(performance.now() / 30) * 4 : Math.sin(performance.now() / 90) * s * 3;
        f.style.transform = `translate(-50%,-50%) scale(${k}) rotate(${w}deg)`; f.classList.toggle('max', s >= 1);
        hold.raf = requestAnimationFrame(grow); };
      grow();
    }, 220);
  }, true);
  function endHold(e, cancel){
    if (!hold) return; const h = hold; hold = null; clearTimeout(h.timer); cancelAnimationFrame(h.raf);
    if (h.snd) h.snd.stop();
    if (!h.on) return;   // fue un toque: sigue el comportamiento normal (inserta el emoji)
    // se mantuvo: no inserta, se manda grande
    const swallow = ev => { ev.stopPropagation(); ev.preventDefault(); }; document.addEventListener('click', swallow, true); setTimeout(() => document.removeEventListener('click', swallow, true), 60);
    const n = levelOf(performance.now() - h.t0 - 220), f = h.el;
    if (cancel){ if (f) f.remove(); return; }
    if (f){ const box = document.getElementById(lastTA && lastTA.id === 'ccw-ta' ? 'ccw-msgs' : 'cc-msgs') || document.getElementById('ccw-msgs'); const r = box ? box.getBoundingClientRect() : { right: innerWidth * .7, bottom: innerHeight * .6 };
      f.animate([{ transform: f.style.transform, opacity: 1 }, { transform: `translate(${r.right - 80 - h.x}px, ${r.bottom - 90 - h.y}px) translate(-50%,-50%) scale(1.2)`, opacity: .2 }], { duration: 380, easing: 'cubic-bezier(.4,.1,.3,1)', fill: 'forwards' }).onfinish = () => f.remove();
      setTimeout(() => f.remove(), 600); }
    play('swoosh', 0, true);
    sendHuge(h.em, n);
    // cerrar el selector
    const pk = h.b.closest('.cc-emo, .cc-pk'); const q = pk && pk.querySelector('input');
    if (q) q.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); else if (pk) pk.remove();
  }
  document.addEventListener('pointerup', e => endHold(e, false), true);
  document.addEventListener('pointercancel', e => endHold(e, true), true);
  function sendHuge(em, n){
    const ta = (lastTA && lastTA.isConnected) ? lastTA : (document.getElementById('ccw-ta') && !document.getElementById('ccw-panel')?.hidden ? document.getElementById('ccw-ta') : document.getElementById('cc-ta'));
    if (!ta) return;
    const keep = ta.value; ta.value = em + HUGE.repeat(n); ta.dispatchEvent(new Event('input', { bubbles: true }));
    if (ta.id === 'ccw-ta'){ const form = document.getElementById('ccw-comp'); if (form) form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true })); }
    else { const btn = document.getElementById('cc-send'); if (btn){ btn.disabled = false; btn.click(); } }
    setTimeout(() => { if (keep && !ta.value){ ta.value = keep; ta.dispatchEvent(new Event('input', { bubbles: true })); } }, 50);
  }
  // marcar los emojis grandes en la conversación (los dos chats); los nuevos de otra persona caen con impacto
  function hugeLevel(el){
    const c = el.cloneNode(true); c.querySelectorAll('.who, .tm, .rq, .cc-edited').forEach(x => x.remove());   // solo el texto del mensaje
    const t = c.textContent || '', m = t.match(/⁣+/); if (!m) return 0;
    return onlyEmojis(t.replace(/⁣/g, '')) ? Math.min(3, m[0].length) : 0;
  }
  function hugeMark(el, cfg){
    const tx = (cfg.box === 'cc-msgs' ? el.querySelector('.cc-body') : el.querySelector('.ccw-b')) || null; if (!tx || tx.__g7jHuge !== undefined) return 0;
    const n = hugeLevel(tx); tx.__g7jHuge = n; if (!n) return 0;
    el.classList.add('g7j-hugemsg'); tx.classList.add('g7j-huge', 's' + n); return n;
  }
  function impact(el, n, mine){
    const tx = el.querySelector('.g7j-huge') || el;
    tx.animate([{ transform: 'translateY(-120px) scale(.4) rotate(-20deg)', opacity: 0 }, { transform: 'translateY(0) scale(1.25, .7)', opacity: 1, offset: .55 }, { transform: 'scale(.9, 1.12)', offset: .72 }, { transform: 'scale(1.04, .97)', offset: .86 }, { transform: 'none' }],
      { duration: 700, easing: 'cubic-bezier(.3,.7,.4,1)' });
    setTimeout(() => {
      if (!mine){ play('thump', 0, true); const box = el.closest('#ccw-panel, .cc-main, #cc-msgs') || el.parentElement; if (box) box.animate([{ translate: '0 0' }, { translate: '-6px 3px' }, { translate: '5px -3px' }, { translate: '-3px 2px' }, { translate: '0 0' }], { duration: 320, easing: 'ease-out' }); }
      const r = tx.getBoundingClientRect(); for (let k = 0; k < 2; k++){ const w = document.createElement('div'); w.className = 'g7j-ring'; w.style.left = (r.left + r.width / 2) + 'px'; w.style.top = (r.top + r.height / 2) + 'px'; w.style.animationDelay = (k * .12) + 's'; document.body.appendChild(w); setTimeout(() => w.remove(), 800); }
      emojiBurst([tx.textContent.replace(/⁣/g, '').trim().slice(0, 8)], { x: r.left + r.width / 2, y: r.top + r.height / 2 }, 4 + n * 2, .7);
    }, 380);
  }

  /* ============================== números que cuentan ============================== */
  const NUMSEL = 'b, strong, [class*="kpi"] > *, [class*="big"], [class*="stat"] > *';   // se filtran por tamaño de letra
  const counted = new Map();   // etiqueta del número → último valor mostrado (para no repetir en cada refresco)
  function countUp(el){
    const txt = el.textContent.trim(); if (!txt || txt.length > 22 || el.children.length) return;
    if (!/^[^\d]{0,4}\d[\d.,]*\s?[^\d\s]{0,4}$/.test(txt)) return;                 // un número, con algo de prefijo o sufijo ($, %, h)
    if (el.closest('input, textarea, select, [contenteditable], .g7-still-zone')) return;
    if (parseFloat(getComputedStyle(el).fontSize) < 20) return;                         // solo los números grandes de los paneles
    const m = txt.match(/\d[\d.,]*/);
    const par = el.parentNode, label = ((par && par.querySelector('small')) || {}).textContent || ((par && par.className) || '') + '#' + [...(par ? par.children : [])].indexOf(el);
    const key = location.pathname + '|' + label, prev = counted.get(key); if (prev === txt) return; counted.set(key, txt);
    const raw = m[0], commaDec = /,\d{1,2}$/.test(raw), dotDec = !commaDec && /\.\d{1,2}$/.test(raw) && (raw.match(/\./g) || []).length === 1;
    const dec = commaDec || dotDec ? raw.split(/[.,]/).pop().length : 0;
    const val = parseFloat(commaDec ? raw.replace(/\./g, '').replace(',', '.') : dotDec ? raw.replace(/,/g, '') : raw.replace(/[.,]/g, ''));
    if (!(val > 0) || val > 1e10) return;
    // la primera vez cuenta desde cero; si el número cambia después (por una acción), va del valor anterior al nuevo
    let from = 0;
    if (prev !== undefined){ const pm = prev.match(/\d[\d.,]*/); from = pm ? parseFloat(commaDec ? pm[0].replace(/\./g, '').replace(',', '.') : dotDec ? pm[0].replace(/,/g, '') : pm[0].replace(/[.,]/g, '')) || 0 : 0; }
    const sep = commaDec || (!dotDec && /\.\d{3}/.test(raw)) ? 'es-AR' : 'en-US';
    const fmt = v => v.toLocaleString(sep, { minimumFractionDigits: dec, maximumFractionDigits: dec, useGrouping: /[.,]\d{3}/.test(raw) });
    const pre = txt.slice(0, m.index), post = txt.slice(m.index + raw.length), t0 = performance.now(), dur = prev !== undefined ? 450 : Math.min(1200, 500 + Math.log10(val + 1) * 220);
    const step = now => { if (!el.isConnected) return; const u = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - u, 3);
      const v = from + (val - from) * e; el.textContent = u >= 1 ? txt : pre + fmt(dec ? v : Math.round(v)) + post; if (u < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  }
  function scanNums(node){ if (!motion || !node.querySelectorAll) return; (node.matches && node.matches(NUMSEL) ? [node] : []).concat([...node.querySelectorAll(NUMSEL)]).forEach(countUp); }
  const mo2 = new MutationObserver(list => { for (const m of list) for (const n of m.addedNodes) if (n.nodeType === 1) scanNums(n); });
  const start2 = () => { scanNums(document.body); mo2.observe(document.body, { childList: true, subtree: true }); };
  if (document.body) start2(); else document.addEventListener('DOMContentLoaded', start2);

  /* ============================== avisos: chat, notificaciones y zumbidos ==============================
     Estos sonidos los piden topbar.js y el chat, que ya deciden si corresponde (preferencias de cada uno):
     por eso suenan aunque "Sonidos de la interfaz" esté apagado y aunque la pestaña esté en segundo plano. */
  let echo = null;
  function echoBus(){   // un eco corto y oscuro que les da cuerpo a las campanas
    const c = ctx(); if (!c) return null; if (echo) return echo;
    const inp = c.createGain(), d = c.createDelay(1), fb = c.createGain(), lp = c.createBiquadFilter(), wet = c.createGain();
    d.delayTime.value = .17; fb.gain.value = .32; lp.type = 'lowpass'; lp.frequency.value = 2600; wet.gain.value = .3;
    inp.connect(d); d.connect(lp); lp.connect(fb); fb.connect(d); lp.connect(wet); wet.connect(out);
    return echo = inp;
  }
  function bell(f, dur = 1, o = {}){   // campana: parciales inarmónicos que se apagan a distinto ritmo
    const c = ctx(); if (!c) return; const t = c.currentTime + (o.at || 0), bus = echoBus();
    [[1, 1, 1], [2.76, .38, .5], [5.4, .16, .3], [.5, .12, 1.2]].forEach(([k, a, len]) => {
      const osc = c.createOscillator(), g = c.createGain(); osc.type = 'sine'; osc.frequency.value = f * k;
      env(g, t, .004, (o.vol || .3) * a, dur * len); osc.connect(g); g.connect(out); if (bus) g.connect(bus); osc.start(t); osc.stop(t + dur * len + .05); });
  }
  function mallet(f, o = {}){   // marimba: cuerpo + un golpe agudo muy corto
    const c = ctx(); if (!c) return; const t = c.currentTime + (o.at || 0), bus = echoBus();
    [[1, 1, .5], [4, .25, .08], [10, .06, .03]].forEach(([k, a, len]) => {
      const osc = c.createOscillator(), g = c.createGain(); osc.type = 'sine'; osc.frequency.value = f * k;
      env(g, t, .003, (o.vol || .4) * a, len); osc.connect(g); g.connect(out); if (bus && k === 1) g.connect(bus); osc.start(t); osc.stop(t + len + .05); });
  }
  function drop(f, o = {}){   // gota: subida rápida de tono, como una burbuja
    const c = ctx(); if (!c) return; const t = c.currentTime + (o.at || 0), osc = c.createOscillator(), g = c.createGain(), bus = echoBus();
    osc.type = 'sine'; osc.frequency.setValueAtTime(f * .55, t); osc.frequency.exponentialRampToValueAtTime(f, t + .06); osc.frequency.exponentialRampToValueAtTime(f * 1.08, t + .16);
    env(g, t, .006, o.vol || .4, .18); osc.connect(g); g.connect(out); if (bus) g.connect(bus); osc.start(t); osc.stop(t + .22);
  }
  const ALERT = {
    notify:  () => { bell(1318.5, .9, { vol: .26 }); bell(1975.5, 1.1, { vol: .2, at: .11 }); },
    mention: () => { [1568, 1975.5, 2349.3].forEach((f, i) => bell(f, .7, { vol: .2, at: i * .075 })); },
    task:    () => { [523.3, 659.3, 784].forEach((f, i) => mallet(f, { vol: .38, at: i * .07 })); },
    ticket:  () => { mallet(1174.7, { vol: .4 }); mallet(880, { vol: .36, at: .12 }); },
    remind:  () => { bell(880, 1.4, { vol: .24 }); bell(1318.5, 1.4, { vol: .18, at: .02 }); bell(880, 1.2, { vol: .16, at: .45 }); },
    alert:   () => { tone(330, .09, { type: 'triangle', vol: .34 }); tone(330, .12, { type: 'triangle', vol: .34, at: .14 }); bell(659.3, .6, { vol: .12, at: .14 }); },
    cash:    () => SFX.cash(),
    dm:      () => { drop(880, { vol: .36 }); drop(1320, { vol: .3, at: .12 }); },
    chat:    () => { drop(1046.5, { vol: .28 }); },
    chatnote:() => { mallet(1568, { vol: .26 }); },
    sent:    () => { hiss(.12, { f: 1200, to: 5200, q: 1.4, vol: .16 }); drop(1760, { vol: .14, at: .07 }); },
  };
  let alertLast = 0;
  function alertSound(name){
    if (!ALERT[name]) name = 'notify';
    const now = performance.now(); if (now - alertLast < 250) return; alertLast = now;
    try { ALERT[name](); } catch (e) {}
    if (window.G7Juice) G7Juice.last = name;
  }
  // El audio solo arranca después de que la persona tocó la página: se prepara con el primer gesto
  ['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, () => { try { ctx(); } catch (e) {} }, { once: true, capture: true }));

  // Zumbido: un "ring ring" de teléfono; urgente = sirena
  function ring(count){
    const c = ctx(); if (!c) return; const urgent = count >= 20, n = Math.min(count, urgent ? 24 : 8);
    for (let i = 0; i < n; i++){
      const at = i * (urgent ? .22 : .9);
      if (urgent){ tone(i % 2 ? 900 : 1300, .2, { type: 'square', vol: .1, at }); continue; }
      for (let k = 0; k < 2; k++){ const t0 = at + k * .2, osc = c.createOscillator(), osc2 = c.createOscillator(), g = c.createGain(), t = c.currentTime + t0;
        osc.frequency.value = 1320; osc2.frequency.value = 1100; env(g, t, .01, .14, .17); osc.connect(g); osc2.connect(g); g.connect(out); osc.start(t); osc2.start(t); osc.stop(t + .2); osc2.stop(t + .2); }
    }
  }

  // Tipo de notificación (notif.php) → sonido; con varias juntas suena la más importante
  const PRIO = ['cash', 'mention', 'alert', 'ticket', 'task', 'remind', 'notify'];
  function soundFor(t){
    t = String(t || '');
    if (t === 'mention' || /mention/.test(t)) return 'mention';
    if (t === 'pay') return 'cash';
    if (/^task_/.test(t)) return 'task';
    if (/^(wk_|pt_tk)/.test(t)) return 'ticket';
    if (/remind|cal_/.test(t)) return 'remind';
    if (/login|pass/.test(t)) return 'alert';
    return 'notify';
  }
  // Tarjetas de aviso en pantalla
  let stack = null;
  function notifyCards(list){
    if (document.hidden) return;   // con la pestaña oculta ya avisa el sistema operativo
    if (!stack){ stack = document.createElement('div'); stack.className = 'g7j-stack'; document.body.appendChild(stack); }
    list.slice(0, 3).reverse().forEach(n => {
      const card = document.createElement('div'); card.className = 'g7j-card'; card.setAttribute('role', 'status');
      const icon = typeof window.notifIcon === 'function' ? notifIcon(n.type) : '<i class="fa-solid fa-bell"></i>';
      const text = typeof window.notifText === 'function' ? notifText(n) : '';
      const body = n.body ? String(n.body).slice(0, 120) : '';
      card.innerHTML = `<div class="g7j-card-ic">${icon}</div><div class="g7j-card-tx"><div class="g7j-card-t">${text}</div>${body ? '<div class="g7j-card-b"></div>' : ''}<div class="g7j-card-time">ahora</div></div><button class="g7j-card-x" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button><i class="g7j-card-bar"></i>`;
      if (body) card.querySelector('.g7j-card-b').textContent = body;
      stack.prepend(card);
      const life = 6500; let left = life, t0 = performance.now(), timer = 0;
      const bar = card.querySelector('.g7j-card-bar'), barAnim = bar.animate([{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }], { duration: life, fill: 'forwards' });
      const close = () => { clearTimeout(timer); if (!card.isConnected) return;
        const a = motion ? card.animate([{ opacity: 1, translate: '0 0', scale: '1' }, { opacity: 0, translate: '60px 0', scale: '.94' }], { duration: 260, easing: 'ease-in', fill: 'forwards' }) : null;
        const h = card.offsetHeight; setTimeout(() => { if (motion) card.animate([{ height: h + 'px', marginTop: '0px' }, { height: '0px', marginTop: '-10px' }], { duration: 180, fill: 'forwards' }); setTimeout(() => card.remove(), 180); }, a ? 240 : 0); };
      const arm = () => { timer = setTimeout(close, left); t0 = performance.now(); };
      card.addEventListener('mouseenter', () => { clearTimeout(timer); left -= performance.now() - t0; barAnim.pause(); });
      card.addEventListener('mouseleave', () => { barAnim.play(); arm(); });
      card.querySelector('.g7j-card-x').addEventListener('click', e => { e.stopPropagation(); close(); });
      card.addEventListener('click', () => { close(); if (typeof window.clickNotif === 'function') clickNotif(n.id, n.profile_id || 0, n.type, n.target_id || 0); });
      if (motion) card.animate([{ opacity: 0, translate: '80px 0', rotate: '4deg', scale: '.9' }, { opacity: 1, translate: '-6px 0', rotate: '-1deg', scale: '1.02', offset: .6 }, { opacity: 1, translate: '0 0', rotate: '0deg', scale: '1' }], { duration: 560, easing: 'cubic-bezier(.2,.8,.2,1)' });
      arm();
    });
    while (stack.children.length > 4) stack.lastElementChild.remove();
  }
  function bellJuice(){
    const btn = document.getElementById('notif-btn'), badge = document.getElementById('notif-badge');
    if (!motion) return;
    if (btn){ const i = btn.querySelector('i') || btn; i.animate([{ rotate: '0deg' }, { rotate: '22deg' }, { rotate: '-18deg' }, { rotate: '14deg' }, { rotate: '-9deg' }, { rotate: '4deg' }, { rotate: '0deg' }], { duration: 900, easing: 'ease-out' }); }
    if (badge){ badge.animate([{ scale: '.4' }, { scale: '1.45', offset: .45 }, { scale: '1' }], { duration: 520, easing: SPRING }); badge.classList.remove('g7j-glow'); void badge.offsetWidth; badge.classList.add('g7j-glow'); }
  }
  // topbar.js llama esto con las notificaciones nuevas
  function notify(list, opts = {}){
    if (!list || !list.length) return;
    if (opts.sound !== false){ const best = list.map(n => soundFor(n.type)).sort((a, b) => PRIO.indexOf(a) - PRIO.indexOf(b))[0]; alertSound(best); }
    bellJuice(); notifyCards(list);
  }

  /* ============================== chat: mensajes que llegan y "escribiendo" ==============================
     Funciona sobre el chat completo (#cc-msgs, #cc-typing) y la burbuja (#ccw-msgs, #ccw-typing, #ccw-heads)
     sin tocar su código: los dos redibujan la lista entera, así que se recuerda qué mensajes ya se vieron. */
  const CHATS = [
    { box: 'ccw-msgs', typing: 'ccw-typing', item: '.ccw-m', key: el => el.dataset.mid, mine: el => el.classList.contains('me'), bubble: '.ccw-b' },
    { box: 'cc-msgs', typing: 'cc-typing', item: '.cc-m', key: el => el.dataset.id, mine: () => false, bubble: null },
  ];
  const recentTexts = new Map();   // texto → momento (el mensaje "enviando" y el confirmado son dos elementos distintos)
  /* Anclado al final: el chat baja al último mensaje al dibujar, pero después crecen cosas (imágenes que terminan de
     cargar, tarjetas de tickets que se completan, emojis inflados) y el final queda más abajo: parecía que la ventana
     "subía sola". Si la persona estaba abajo, se la mantiene abajo; si subió a leer, no se la toca. Corre siempre,
     con o sin animaciones. */
  function chatPin(box){
    if (!box || box.__g7pin) return; box.__g7pin = true;
    let stick = true, userAt = 0;
    const near = () => box.scrollHeight - box.scrollTop - box.clientHeight < 90;
    const mark = e => {
      userAt = performance.now();
      if (e && e.type === 'wheel' && e.deltaY < 0) stick = false;   // rueda hacia arriba: quiere leer lo de antes
      setTimeout(() => { stick = near(); }, 120);
    };
    ['wheel', 'touchmove', 'pointerdown', 'keydown'].forEach(t => box.addEventListener(t, mark, { passive: true }));
    box.addEventListener('scroll', () => {
      // Solo lo que hace la persona cambia el anclaje; los saltos de los redibujos no
      if (performance.now() - userAt < 1200) stick = near();
      else if (near()) stick = true;
    }, { passive: true });
    const fix = () => {
      if (!stick || near()) return;
      if (performance.now() - userAt < 1500) { stick = false; return; }   // la persona se está moviendo: no se la arrastra
      box.scrollTop = box.scrollHeight;
    };
    box.__g7stick = () => stick;
    let raf = 0; const soon = () => { if (!raf) raf = requestAnimationFrame(() => { raf = 0; fix(); }); setTimeout(fix, 60); };
    box.addEventListener('load', soon, true);              // imágenes y videos (load no sube: en captura)
    const ro = new ResizeObserver(soon);
    const watch = () => box.querySelectorAll(':scope > *').forEach(el => { if (!el.__g7ro){ el.__g7ro = 1; ro.observe(el); } });
    new MutationObserver(() => { watch(); soon(); }).observe(box, { childList: true });
    watch();
  }
  function chatWatch(cfg){
    const box = document.getElementById(cfg.box); if (!box || box.__g7j) return; box.__g7j = true;
    chatPin(box);
    let seen = new Set(), first = true, typingEl = null;
    const keyOf = el => cfg.key(el) || ('p:' + (el.textContent || '').trim().slice(0, 80));   // sin id todavía (enviando): por el texto
    const sync = () => {
      const items = [...box.querySelectorAll(cfg.item)], fresh = items.filter(el => { const k = keyOf(el); return k && !seen.has(k); });
      items.forEach(el => { const k = keyOf(el); if (k) seen.add(k); });
      items.forEach(el => hugeMark(el, cfg));   // emojis inflados (tamaño en el mensaje)
      if (first || fresh.length > 5){ first = !items.length; return; }   // carga de una conversación: sin animar
      if (!motion) return;
      const now = performance.now();
      fresh.forEach((el, i) => {
        const txt = (el.textContent || '').trim().slice(0, 80);
        if (txt && now - (recentTexts.get(txt) || -1e9) < 15000) return; recentTexts.set(txt, now);
        const mine = cfg.mine(el) || el.classList.contains('pending'), target = (cfg.bubble && el.querySelector(cfg.bubble)) || el;
        if (el.classList.contains('g7j-hugemsg')){ impact(el, +(el.querySelector('.g7j-huge')?.className.match(/s(\d)/) || [0, 1])[1], mine); return; }
        target.style.transformOrigin = mine ? '100% 100%' : '0% 100%';
        target.animate([{ opacity: 0, scale: '.4', translate: (mine ? '24px' : '-24px') + ' 10px' }, { opacity: 1, scale: '1.06', translate: '0 -2px', offset: .55 }, { opacity: 1, scale: '1', translate: '0 0' }],
          { duration: 480, delay: i * 70, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' });
      });
      if (typingEl && box.lastElementChild !== typingEl) box.appendChild(typingEl);   // la lista se redibujó: el globito vuelve al final
    };
    new MutationObserver(sync).observe(box, { childList: true });
    sync();
    // Globito de "escribiendo" dentro de la conversación, como en el teléfono
    const tl = document.getElementById(cfg.typing);
    if (tl){
      const upd = () => {
        const on = /escribiendo/i.test(tl.textContent || '');
        if (on && !typingEl){
          typingEl = document.createElement('div'); typingEl.className = 'g7j-typing'; typingEl.setAttribute('aria-hidden', 'true');
          typingEl.innerHTML = '<span class="g7j-dots"><i></i><i></i><i></i></span>';
          const near = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
          box.appendChild(typingEl); if (near) box.scrollTop = box.scrollHeight;
          if (motion) typingEl.animate([{ opacity: 0, scale: '.3' }, { opacity: 1, scale: '1.1', offset: .6 }, { opacity: 1, scale: '1' }], { duration: 380, easing: 'cubic-bezier(.2,.8,.2,1)' });
        } else if (!on && typingEl){
          const el = typingEl; typingEl = null;
          if (motion) el.animate([{ opacity: 1, scale: '1' }, { opacity: 0, scale: '.5' }], { duration: 180, fill: 'forwards' });
          setTimeout(() => el.remove(), motion ? 190 : 0);   // con la pestaña en segundo plano la animación se pausa: se borra igual
        }
      };
      new MutationObserver(upd).observe(tl, { childList: true, characterData: true, subtree: true });
      upd();
    }
  }
  // Cabezas de la burbuja: una nueva entra con rebote; cuando sube el contador de no leídos, salta
  function headsWatch(){
    const box = document.getElementById('ccw-heads'); if (!box || box.__g7j) return; box.__g7j = true;
    let counts = new Map(), first = true;
    new MutationObserver(() => {
      const next = new Map();
      box.querySelectorAll('.ccw-head').forEach(h => {
        const id = h.dataset.head, n = parseInt((h.querySelector('.n') || {}).textContent || '0', 10) || 0; next.set(id, n);
        if (first || !motion) return;
        if (!counts.has(id)) h.animate([{ opacity: 0, scale: '.2', translate: '30px 0' }, { opacity: 1, scale: '1.15', translate: '0 0', offset: .6 }, { opacity: 1, scale: '1' }], { duration: 520, easing: 'cubic-bezier(.2,.8,.2,1)' });
        else if (n > counts.get(id)){
          h.animate([{ translate: '0 0' }, { translate: '0 -12px', scale: '1.12' }, { translate: '0 0', scale: '.94' }, { translate: '0 -4px', scale: '1.03' }, { translate: '0 0', scale: '1' }], { duration: 650, easing: 'ease-out' });
          h.classList.remove('g7j-ping'); void h.offsetWidth; h.classList.add('g7j-ping');
          const b = h.querySelector('.n'); if (b) b.animate([{ scale: '.4' }, { scale: '1.4', offset: .5 }, { scale: '1' }], { duration: 480, easing: SPRING });
        }
      });
      counts = next; first = false;
    }).observe(box, { childList: true });
  }
  const chatBoot = () => { CHATS.forEach(chatWatch); headsWatch(); chatPin(document.getElementById('cc-tmsgs')); };
  // Los chats arman sus cajas después de cargar (el completo al abrir una conversación): se vuelve a mirar cuando aparecen
  new MutationObserver(() => { const t = document.getElementById('cc-tmsgs'); if (!document.getElementById('cc-msgs') || !document.getElementById('cc-msgs').__g7j || !document.getElementById('ccw-msgs') || !document.getElementById('ccw-msgs').__g7j || (t && !t.__g7pin)) chatBoot(); })
    .observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState !== 'loading') chatBoot(); else document.addEventListener('DOMContentLoaded', chatBoot);

  /* ============================== editor de formularios de tickets (Trabajo › Solicitudes › Formularios) ==============================
     trabajo.js redibuja #fm-canvas entero en cada cambio y sus funciones son internas: se anima por diferencia,
     sabiendo qué se tocó por el clic (capturado antes que trabajo.js). Misma experiencia que el constructor del portal. */
  (function ticketForms(){
    let canvas = null, pending = null, snap = null, firstDraw = true;
    const fields = () => canvas ? [...canvas.querySelectorAll('#fm-fields > .wk-wy-f[data-i]')] : [];
    const rects = () => fields().map(el => ({ el, r: el.getBoundingClientRect() }));
    function sparks(x, y){
      for (let i = 0; i < 9; i++){ const s = document.createElement('i'); s.className = 'g7j-spark'; s.style.left = x + 'px'; s.style.top = y + 'px'; document.body.appendChild(s);
        const a = i / 9 * Math.PI * 2, v = 18 + Math.random() * 22;
        s.animate([{ transform: 'translate(0,0) scale(1)', opacity: 1 }, { transform: `translate(${Math.cos(a) * v}px,${Math.sin(a) * v}px) scale(.2)`, opacity: 0 }], { duration: 480, easing: 'cubic-bezier(.2,.8,.2,1)' }).onfinish = () => s.remove(); }
    }
    function land(el, from){
      const go = () => { el.animate([{ opacity: 0, scale: '.7', translate: '0 -16px' }, { opacity: 1, scale: '1.03', translate: '0 3px', offset: .6 }, { opacity: 1, scale: '1', translate: '0 0' }], { duration: 520, easing: 'cubic-bezier(.2,.8,.2,1)' });
        const r = el.getBoundingClientRect(); sparks(r.left + 30, r.top + 16); play('pop'); };
      if (!from) return go();
      const to = el.getBoundingClientRect(), chip = document.createElement('div'); chip.className = 'g7j-flychip'; chip.innerHTML = from.html; document.body.appendChild(chip);
      const x0 = from.r.left, y0 = from.r.top, x1 = to.left + 20, y1 = to.top + 10, mx = (x0 + x1) / 2, my = Math.min(y0, y1) - 80;
      el.style.opacity = '0';
      const a = chip.animate([{ transform: `translate(${x0}px,${y0}px)`, opacity: 1 }, { transform: `translate(${mx}px,${my}px) scale(1.1) rotate(-6deg)`, offset: .5, opacity: 1 }, { transform: `translate(${x1}px,${y1}px) scale(.8)`, opacity: .2 }], { duration: 430, easing: 'cubic-bezier(.4,.1,.3,1)' });
      const end = () => { if (!chip.isConnected) return; chip.remove(); el.style.opacity = ''; go(); };
      a.onfinish = end; setTimeout(end, 700);
    }
    function after(){
      if (!pending || !motion){ pending = null; return; }
      const p = pending; pending = null; const now = fields();
      if (p.kind === 'add'){ const el = now[now.length - 1]; if (el){ el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); land(el, p.from); } return; }
      if (p.kind === 'rm'){
        const g = p.clone; Object.assign(g.style, { position: 'fixed', left: p.r.left + 'px', top: p.r.top + 'px', width: p.r.width + 'px', margin: 0, zIndex: 60, pointerEvents: 'none' });
        g.classList.add('g7j-gone'); document.body.appendChild(g);
        g.animate([{ opacity: 1, scale: '1' }, { opacity: 0, scale: '.75', rotate: '-3deg', translate: '0 14px' }], { duration: 320, easing: 'ease-in', fill: 'forwards' }); setTimeout(() => g.remove(), 360);
      }
      // los que cambiaron de lugar se deslizan (mapa de índice viejo → nuevo según la acción)
      now.forEach((el, j) => {
        let i = j;
        if (p.kind === 'rm') i = j >= p.i ? j + 1 : j;
        if (p.kind === 'move'){ const a = p.from, b = p.to; if (j === b) i = a; else if (a < b && j >= a && j < b) i = j + 1; else if (a > b && j > b && j <= a) i = j - 1; }
        const old = p.snap[i]; if (!old) return;
        const r = el.getBoundingClientRect(), dx = old.r.left - r.left, dy = old.r.top - r.top;
        if (Math.abs(dy) > 1 || Math.abs(dx) > 1) el.animate([{ translate: `${dx}px ${dy}px` }, { translate: '0 0' }], { duration: 440, easing: 'cubic-bezier(.3,1.25,.6,1)' });
        if (p.kind === 'move' && j === p.to) el.animate([{ scale: '1.03' }, { scale: '1' }], { duration: 420, easing: 'ease-out' });
      });
      if (p.kind === 'req' || p.kind === 'opt'){
        const el = now[p.i]; if (!el) return;
        const t = p.kind === 'req' ? el.querySelector('[data-req]') : el.querySelector('.wk-wy-opts .o:last-of-type');
        if (t) t.animate([{ scale: '.6' }, { scale: '1.15', offset: .6 }, { scale: '1' }], { duration: 420, easing: 'cubic-bezier(.2,.8,.2,1)' });
      }
      if (p.kind === 'look'){ const ic = canvas.querySelector('#fm-ic'); if (ic) ic.animate([{ scale: '1', rotate: '0deg' }, { scale: '1.25', rotate: '-12deg' }, { scale: '1', rotate: '0deg' }], { duration: 480, easing: 'cubic-bezier(.2,.8,.2,1)' }); }
    }
    // qué se va a tocar: se mira antes de que trabajo.js redibuje
    document.addEventListener('click', e => {
      if (!canvas || !canvas.contains(e.target)) return;
      const f = e.target.closest('.wk-wy-f[data-i]'), i = f ? +f.dataset.i : -1, s = rects();
      const add = e.target.closest('[data-addf]');
      if (add){ const r = add.getBoundingClientRect(); add.animate([{ scale: '1' }, { scale: '.85' }, { scale: '1.08' }, { scale: '1' }], { duration: 380 }); pending = { kind: 'add', from: { r, html: add.innerHTML }, snap: s }; return; }
      if (e.target.closest('[data-rm]') && f){ pending = { kind: 'rm', i, r: f.getBoundingClientRect(), clone: f.cloneNode(true), snap: s }; play('swoosh'); return; }
      if (e.target.closest('[data-req]') && f){ pending = { kind: 'req', i, snap: s }; play(f.querySelector('[data-req].on') ? 'off' : 'on'); return; }
      if (e.target.closest('[data-optadd]') && f){ pending = { kind: 'opt', i, snap: s }; play('pop'); return; }
      if (e.target.closest('[data-c], [data-ic]')){ pending = { kind: 'look', snap: s }; play('tap'); return; }
      if (e.target.closest('#fm-ic')){ play(canvas.querySelector('#fm-pick[hidden]') ? 'open' : 'close'); const pk = canvas.querySelector('#fm-pick');
        setTimeout(() => { if (pk && !pk.hidden && motion) [...pk.querySelectorAll('button')].forEach((b, k) => b.animate([{ opacity: 0, scale: '.4' }, { opacity: 1, scale: '1' }], { duration: 300, delay: Math.min(k, 30) * 12, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' })); }, 0); }
    }, true);
    let dragFrom = -1;
    document.addEventListener('dragstart', e => { if (!canvas || !canvas.contains(e.target)) return; const f = e.target.closest('.wk-wy-f[data-i]'); if (f){ dragFrom = +f.dataset.i; play('lift'); } }, true);
    document.addEventListener('drop', e => {
      if (!canvas || !canvas.contains(e.target) || dragFrom < 0) return;
      const r = e.target.closest('.wk-wy-f[data-i]'), to = r ? +r.dataset.i : fields().length - 1;
      pending = { kind: 'move', from: dragFrom, to, snap: rects() }; dragFrom = -1; play('drop');
    }, true);
    // cuándo aparece el editor y cuándo se redibuja
    const watch = () => {
      const c = document.getElementById('fm-canvas'); if (c === canvas) return; canvas = c; if (!c) return; firstDraw = !c.children.length;   // si ya se dibujó, el próximo cambio ya es de la persona
      new MutationObserver(() => { if (firstDraw){ firstDraw = false; return; } after(); }).observe(c, { childList: true });
    };
    new MutationObserver(watch).observe(document.documentElement, { childList: true, subtree: true });
    if (document.readyState !== 'loading') watch(); else document.addEventListener('DOMContentLoaded', watch);
  })();

  window.G7Juice = {
    celebrate, notify, alert: alertSound, ring, fieldError, _inspect: inspect, _chat: chatInspect, _counted: counted,
    force(){ try { localStorage.setItem('g7-motion', 'on'); } catch (e) {} location.reload(); },
    off(){ try { localStorage.setItem('g7-motion', 'off'); } catch (e) {} location.reload(); },
    on(){ try { localStorage.removeItem('g7-motion'); } catch (e) {} location.reload(); },
    sound(v){ sound = !!v; }, test(n){ const s = sound; sound = true; play(n || 'success', 0); sound = s; },
    // Para g7-tools.js (herramientas): las mismas piezas, sin duplicar el motor
    _fx: { play, confetti, chip, tone, hiss, ctx },
    get motion(){ return motion; }, get soundOn(){ return sound; },
  };
})();
