/* assets/onboarding-juice.js — El onboarding se hace una sola vez: que se sienta especial.
 * Capa sobre onboarding.js (no toca su lógica): mira #ob-main y le pone movimiento, sonido y festejos a cada momento.
 *   Bienvenida   fondo vivo, título que entra letra por letra, el camino de pasos con notas, botón que respira y se deja atraer
 *   Comenzar     estallido de confeti y despegue hacia el primer paso
 *   Pasos        barra que avanza con resorte y chispa en la punta; cada paso terminado suma una nota (sube la escala)
 *   Perfil       cada dato completo hace "pop" con tilde; medidor de perfil; la foto entra con aros
 *   Preferencias el tema elegido se derrama desde el clic; interruptores con sonido; animaciones y sonidos se prueban al momento
 *   Seguridad    al activar la verificación, escudo y festejo grande
 *   Final        fanfarria y fuegos artificiales
 * El movimiento respeta html.g7-move; el sonido, la preferencia (en el onboarding viene prendido si la persona no eligió).
 * Doc: toolz/MOVIMIENTO.md › Onboarding */
(function () {
  'use strict';
  const main = document.getElementById('ob-main'); if (!main) return;
  const root = document.documentElement;
  const J = () => window.G7Juice || null, F = () => (J() && J()._fx) || null;
  const moving = () => root.classList.contains('g7-move');
  const play = (n, gap = 0, force = true) => { const f = F(); if (f) f.play(n, gap, force); };
  const tone = (fr, d, o) => { const f = F(), j = J(); if (!f || !j || !j.soundOn || document.hidden) return; try { f.tone(fr, d, o); } catch (e) {} };
  const confetti = (n, o, pal, opt) => { const f = F(); if (f && moving()) f.confetti(n, o, pal, opt); };
  const SPRING = 'cubic-bezier(.34,1.56,.64,1)';
  const acc = () => getComputedStyle(root).getPropertyValue('--ob-a').trim() || getComputedStyle(root).getPropertyValue('--g7-accent').trim() || '#ff6600';
  // escala para que cada logro suba de tono (do mayor)
  const SCALE = [523.3, 587.3, 659.3, 698.5, 784, 880, 987.8, 1046.5, 1174.7, 1318.5, 1396.9, 1568];
  let note = 0;
  const ding = (k = note++) => { const f = SCALE[k % SCALE.length] * (k >= SCALE.length ? 2 : 1); tone(f, .16, { type: 'triangle', vol: .26 }); tone(f * 2, .22, { type: 'sine', vol: .08, at: .03 }); };

  // Sonido: en el onboarding viene prendido salvo que la persona lo haya apagado (bienvenida.php pasa data-ob-sound)
  const me = document.currentScript;
  const wantSound = me && me.dataset.obSound === '1';
  const bootSound = () => { const j = J(); if (j && wantSound) j.sound(true); };
  if (document.readyState === 'complete') bootSound(); else addEventListener('load', bootSound);

  // Botón de sonido en la barra del onboarding
  const top = document.querySelector('.ob-top');
  if (top){
    const b = document.createElement('button'); b.type = 'button'; b.className = 'obj-snd'; b.title = 'Sonido'; b.setAttribute('aria-label', 'Sonido');
    const draw = () => { const on = J() && J().soundOn; b.innerHTML = `<i class="fa-solid ${on ? 'fa-volume-high' : 'fa-volume-xmark'}"></i>`; b.classList.toggle('off', !on); };
    b.addEventListener('click', () => { const j = J(); if (!j) return; j.sound(!j.soundOn); draw(); if (j.soundOn) ding(4); });
    top.insertBefore(b, top.querySelector('.ob-out')); setTimeout(draw, 300); addEventListener('load', () => setTimeout(draw, 50));
  }

  /* ── Fondo vivo: manchas de color que flotan y chispas que suben ── */
  const bg = document.createElement('div'); bg.className = 'obj-bg'; bg.setAttribute('aria-hidden', 'true');
  bg.innerHTML = '<i class="b1"></i><i class="b2"></i><i class="b3"></i>' + Array.from({ length: 18 }, (_, i) => `<s style="--x:${(i * 53) % 100}%;--d:${6 + (i % 7)}s;--w:${(i % 5) * -1.3}s;--z:${.4 + (i % 4) * .25}"></s>`).join('');
  document.body.prepend(bg);

  /* ── Utilidades ── */
  const center = el => { const r = el.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; };
  function sparks(x, y, n = 12, color){
    if (!moving()) return; const c = color || acc();
    for (let i = 0; i < n; i++){
      const s = document.createElement('i'); s.className = 'obj-spark'; s.style.left = x + 'px'; s.style.top = y + 'px'; s.style.background = i % 3 ? c : '#fff'; document.body.appendChild(s);
      const a = (i / n) * Math.PI * 2 + Math.random() * .5, d = 30 + Math.random() * 44;
      s.animate([{ transform: 'translate(-50%,-50%) scale(1)', opacity: 1 }, { transform: `translate(calc(-50% + ${Math.cos(a) * d}px),calc(-50% + ${Math.sin(a) * d}px)) scale(.2)`, opacity: 0 }], { duration: 600 + Math.random() * 250, easing: 'cubic-bezier(.2,.8,.3,1)' }).onfinish = () => s.remove();
      setTimeout(() => s.remove(), 1000);
    }
  }
  function rings(x, y, color){
    if (!moving()) return;
    for (let k = 0; k < 2; k++){ const r = document.createElement('i'); r.className = 'obj-ring'; r.style.left = x + 'px'; r.style.top = y + 'px'; r.style.borderColor = color || acc(); document.body.appendChild(r);
      r.animate([{ transform: 'translate(-50%,-50%) scale(.3)', opacity: .9 }, { transform: 'translate(-50%,-50%) scale(3.2)', opacity: 0 }], { duration: 700, delay: k * 140, easing: 'cubic-bezier(.2,.8,.3,1)', fill: 'backwards' }).onfinish = () => r.remove();
      setTimeout(() => r.remove(), 1100); }
  }
  function chip(text, icon, x, y, color){
    const d = document.createElement('div'); d.className = 'obj-chip'; if (color) d.style.setProperty('--c', color);
    d.innerHTML = `<i class="fa-solid ${icon}"></i><span></span>`; d.querySelector('span').textContent = text; document.body.appendChild(d);
    const w = d.offsetWidth; d.style.left = Math.max(10, Math.min(innerWidth - w - 10, x - w / 2)) + 'px'; d.style.top = Math.max(10, y - 70) + 'px';
    if (moving()) d.animate([{ opacity: 0, transform: 'translateY(14px) scale(.6)' }, { opacity: 1, transform: 'translateY(-6px) scale(1.08)', offset: .5 }, { opacity: 1, transform: 'none', offset: .75 }, { opacity: 0, transform: 'translateY(-26px)' }], { duration: 1700, easing: 'ease-out' }).onfinish = () => d.remove();
    setTimeout(() => d.remove(), moving() ? 1800 : 1400);
  }
  function fireworks(n = 5){
    const f = F(); if (!f || !moving()) return;
    for (let i = 0; i < n; i++) setTimeout(() => {
      const o = { x: innerWidth * (.15 + Math.random() * .7), y: innerHeight * (.15 + Math.random() * .35) };
      f.confetti(34, o, null, { power: .9, spread: 2 }); rings(o.x, o.y, ['#ffd166', '#06d6a0', '#ef476f', '#118ab2', acc()][i % 5]);
      tone(300 + Math.random() * 200, .25, { type: 'sine', vol: .12, to: 900 });
    }, 250 + i * 380);
  }

  /* ── Pantallas ── */
  let lastStep = -1, lastPct = -1, seenWelcome = false, seenFinal = false, seenSecOk = false;
  const filled = new WeakSet();

  function onWelcome(sec){
    if (seenWelcome) return; seenWelcome = true;
    const h = sec.querySelector('h1');
    if (h && moving()){
      // título letra por letra
      const words = h.textContent.split(/(\s+)/); h.textContent = ''; let k = 0;
      words.forEach(w => { if (/^\s+$/.test(w)){ h.append(w); return; } const sp = document.createElement('span'); sp.className = 'obj-word';
        [...w].forEach(ch => { const c = document.createElement('span'); c.className = 'obj-ch'; c.textContent = ch; c.style.setProperty('--k', k++); sp.append(c); }); h.append(sp); });
    }
    // el camino de pasos aparece con notas
    sec.querySelectorAll('.ob-path li').forEach((li, i) => { setTimeout(() => { ding(i); if (moving()) li.animate([{ scale: '.4', opacity: 0 }, { scale: '1.15', opacity: 1, offset: .6 }, { scale: '1', opacity: 1 }], { duration: 500, easing: SPRING, fill: 'backwards' }); }, 700 + i * 220); });
    const go = sec.querySelector('#ob-go');
    if (go){
      go.classList.add('obj-cta');
      // botón "imán": se deja atraer por el mouse
      sec.addEventListener('pointermove', e => { if (!moving()) return; const r = go.getBoundingClientRect(), dx = e.clientX - (r.left + r.width / 2), dy = e.clientY - (r.top + r.height / 2), d = Math.hypot(dx, dy);
        go.style.translate = d < 160 ? `${dx * .12}px ${dy * .18}px` : '0 0'; });
      sec.addEventListener('pointerleave', () => { go.style.translate = '0 0'; });
      go.addEventListener('click', () => {
        const o = center(go); play('swoosh'); setTimeout(() => play('chord'), 120);
        confetti(70, o); rings(o.x, o.y); sparks(o.x, o.y, 18);
        if (moving()) sec.animate([{ transform: 'none', filter: 'none', opacity: 1 }, { transform: 'scale(1.06) translateY(-20px)', filter: 'blur(6px)', opacity: 0 }], { duration: 480, easing: 'cubic-bezier(.5,0,.75,0)', fill: 'forwards' });
      }, true);
    }
  }

  function onWizard(wiz){
    const items = [...wiz.querySelectorAll('.ob-steps li')], step = items.findIndex(li => li.classList.contains('on'));
    const bar = wiz.querySelector('.ob-bar i'), pct = bar ? parseFloat(getComputedStyle(bar).getPropertyValue('--p')) || 0 : 0;
    // barra: crece desde donde estaba, con resorte y chispa en la punta
    if (bar && moving() && lastPct >= 0 && pct !== lastPct){
      bar.animate([{ width: lastPct + '%' }, { width: pct + '%' }], { duration: 750, easing: SPRING });
      setTimeout(() => { const r = bar.getBoundingClientRect(); sparks(r.right, r.top + r.height / 2, 10); }, 520);
    }
    // paso que se completó (avance)
    if (lastStep >= 0 && step > lastStep){
      const done = items[lastStep];
      if (done){ setTimeout(() => { const o = center(done); ding(3 + lastStep * 2); setTimeout(() => ding(4 + lastStep * 2), 110); rings(o.x, o.y, '#22c55e'); sparks(o.x, o.y, 14, '#22c55e');
        if (moving()) done.querySelector('span')?.animate([{ scale: '.3', rotate: '-30deg' }, { scale: '1.35', rotate: '8deg', offset: .6 }, { scale: '1', rotate: '0deg' }], { duration: 600, easing: SPRING });
        chip(['¡Perfil listo!', '¡Preferencias listas!', '¡Cuenta protegida!', '¡Listo!'][lastStep] || '¡Paso listo!', 'fa-circle-check', o.x, o.y + 60, '#22c55e'); }, 120); }
    } else if (lastStep >= 0 && step < lastStep) play('rewind');
    // el paso actual late
    const cur = items[step]; if (cur && moving()) cur.querySelector('span')?.animate([{ scale: '.6' }, { scale: '1.2', offset: .6 }, { scale: '1' }], { duration: 520, easing: SPRING });
    // la tarjeta entra en 3D
    const card = wiz.querySelector('#ob-card');
    if (card && moving()) card.animate([{ opacity: 0, transform: 'perspective(900px) rotateY(' + (step >= lastStep ? 10 : -10) + 'deg) translateX(' + (step >= lastStep ? 30 : -30) + 'px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 520, easing: 'cubic-bezier(.2,.8,.2,1)' });
    if (card) setTimeout(() => cascade(card), 60);
    lastStep = step; lastPct = pct;
    if (card) watchCard(card);
  }

  // Los bloques de la tarjeta entran uno tras otro
  function cascade(card){
    if (!moving()) return;
    [...card.querySelectorAll('.ob-h, .ob-sec, .ob-f, .ob-row, .ob-tog, .ob-tms, .ob-tm-tabs, .ob-nav, .ob-av, .ob-qr, .ob-codes')].slice(0, 24).forEach((el, i) =>
      el.animate([{ opacity: 0, translate: '0 12px' }, { opacity: 1, translate: '0 0' }], { duration: 420, delay: 80 + i * 40, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
  }

  // Perfil: cada dato completo hace "pop"; medidor de perfil
  function watchCard(card){
    if (card.__obj) return; card.__obj = true;
    const fields = () => [...card.querySelectorAll('input.ob-in:not([type=hidden]):not([type=file]), select.ob-in, textarea.ob-in, input[name], select[name]')].filter(x => x.offsetParent);
    const meter = () => {
      const fs = fields(); if (fs.length < 2 || !card.querySelector('.ob-h')) return;
      let m = card.querySelector('.obj-meter');
      if (!m){ m = document.createElement('div'); m.className = 'obj-meter'; m.innerHTML = '<span><i></i></span><b></b>'; card.querySelector('.ob-h').append(m); }
      const n = fs.filter(x => String(x.value || '').trim()).length, p = Math.round(n * 100 / fs.length);
      m.querySelector('i').style.width = p + '%'; m.querySelector('b').textContent = p + '%'; m.classList.toggle('full', p === 100);
      if (p === 100 && !m.__full){ m.__full = true; const o = center(m); play('chime'); sparks(o.x, o.y, 14, '#22c55e'); }
      if (p < 100) m.__full = false;
    };
    setTimeout(meter, 50);
    card.addEventListener('input', meter);
    card.addEventListener('change', e => {
      const x = e.target; meter();
      if (x.matches && x.matches('input[type=checkbox]') && x.closest('.ob-tog')){
        play(x.checked ? 'on' : 'off');
        const sw = x.closest('.ob-tog'); if (moving()) sw.animate([{ scale: '1' }, { scale: '1.03' }, { scale: '1' }], { duration: 260 });
        if (x.checked){ const o = center(x.closest('.ob-tog').querySelector('.sw') || x.closest('.ob-tog')); sparks(o.x, o.y, 8); }
      }
    });
    card.addEventListener('focusout', e => {
      const x = e.target; if (!x.matches || !x.matches('input, select, textarea') || x.type === 'checkbox' || x.type === 'file') return;
      const v = String(x.value || '').trim(); const wrap = x.closest('.ob-f') || x.parentElement;
      if (!v || filled.has(x) && x.__v === v){ return; }
      const ok = x.checkValidity ? x.checkValidity() : true; if (!ok) return;
      filled.add(x); x.__v = v; ding();
      if (wrap){ wrap.classList.add('obj-ok'); let t = wrap.querySelector(':scope > .obj-tick'); if (!t){ t = document.createElement('i'); t.className = 'obj-tick fa-solid fa-check'; wrap.append(t); }
        if (moving()) t.animate([{ scale: '0', rotate: '-60deg' }, { scale: '1.4', rotate: '10deg', offset: .6 }, { scale: '1', rotate: '0deg' }], { duration: 480, easing: SPRING }); }
    });
    // Foto de perfil: el recuadro (#ob-avimg) cambia su fondo cuando se sube la nueva
    const av = card.querySelector('#ob-avimg');
    if (av){
      let prev = av.style.backgroundImage;
      new MutationObserver(() => {
        const now = av.style.backgroundImage; if (!now || now === prev) return; prev = now;
        const o = center(av); play('pop'); setTimeout(() => ding(7), 120); rings(o.x, o.y); sparks(o.x, o.y, 16);
        if (moving()) av.animate([{ scale: '.3', rotate: '-20deg' }, { scale: '1.15', rotate: '6deg', offset: .6 }, { scale: '1', rotate: '0deg' }], { duration: 650, easing: SPRING });
        chip('¡Qué buena foto!', 'fa-camera', o.x, o.y, acc());
      }).observe(av, { attributes: true, attributeFilter: ['style'] });
    }
    // Temas: el color nuevo se derrama desde el clic
    card.addEventListener('click', e => {
      const b = e.target.closest && e.target.closest('[data-theme]'); if (!b) return;
      const bgc = getComputedStyle(b).getPropertyValue('--t-bg').trim() || '#000', ac = getComputedStyle(b).getPropertyValue('--t-acc').trim() || acc();
      play('swoosh'); setTimeout(() => ding(5), 90); sparks(e.clientX, e.clientY, 14, ac);
      if (!moving()) return;
      const w = document.createElement('div'); w.className = 'obj-wash'; w.style.background = `radial-gradient(circle at ${e.clientX}px ${e.clientY}px, ${ac} 0, ${bgc} 30%, ${bgc} 100%)`; document.body.appendChild(w);
      const R = Math.hypot(Math.max(e.clientX, innerWidth - e.clientX), Math.max(e.clientY, innerHeight - e.clientY));
      w.animate([{ clipPath: `circle(0px at ${e.clientX}px ${e.clientY}px)`, opacity: .85 }, { clipPath: `circle(${R}px at ${e.clientX}px ${e.clientY}px)`, opacity: .55, offset: .55 }, { clipPath: `circle(${R}px at ${e.clientX}px ${e.clientY}px)`, opacity: 0 }],
        { duration: 900, easing: 'cubic-bezier(.3,.7,.3,1)' }).onfinish = () => w.remove();
      setTimeout(() => w.remove(), 1200);
      if (moving()) b.animate([{ scale: '1' }, { scale: '1.12' }, { scale: '.96' }, { scale: '1' }], { duration: 420, easing: 'ease-out' });
    });
    // Pestañas de temas
    card.addEventListener('click', e => { if (e.target.closest && e.target.closest('.ob-tm-tab')) play('tap'); });
  }

  function onSecurityOk(el){
    if (seenSecOk) return; seenSecOk = true;
    const o = center(el); const j = J();
    if (j) j.celebrate('big', '¡Cuenta protegida!', 'fa-shield-halved');
    rings(o.x, o.y, '#22c55e'); setTimeout(() => fireworks(2), 300);
  }

  function onFinal(sec){
    if (seenFinal) return; seenFinal = true;
    const o = center(sec.querySelector('.ob-success') || sec);
    setTimeout(() => { const j = J(); if (j) j.celebrate('big', '¡Todo listo!', 'fa-trophy'); }, 250);
    fireworks(6);
    const h = sec.querySelector('h1'); if (h && moving()) h.animate([{ scale: '.6', opacity: 0, letterSpacing: '.2em' }, { scale: '1.08', opacity: 1, offset: .6 }, { scale: '1', opacity: 1, letterSpacing: 'normal' }], { duration: 800, delay: 300, easing: SPRING, fill: 'backwards' });
    const end = sec.querySelector('#ob-end'); if (end){ end.classList.add('obj-cta');
      end.addEventListener('click', ev => { if (!moving()) return; ev.preventDefault(); const c = center(end); play('swoosh'); confetti(50, c); rings(c.x, c.y);
        sec.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'scale(.94) translateY(10px)' }], { duration: 420, fill: 'forwards' }); setTimeout(() => { location.href = end.href; }, 430); }); }
    rings(o.x, o.y, '#22c55e');
  }

  /* ── Mirar qué pantalla está ── */
  const scan = () => {
    const w = main.querySelector('.ob-welcome'); if (w) onWelcome(w);
    const z = main.querySelector('.ob-wiz'); if (z && z !== scan.wiz){ scan.wiz = z; onWizard(z); }
    const f = main.querySelector('.ob-final'); if (f) onFinal(f);
    const ok = main.querySelector('.ob-card .ob-success'); if (ok && !main.querySelector('.ob-final')) onSecurityOk(ok);
  };
  new MutationObserver(scan).observe(main, { childList: true, subtree: true });
  scan();
  window.G7Onboarding = { fireworks, sparks, rings, ding };
})();
