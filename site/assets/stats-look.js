/* assets/stats-look.js — Estadísticas: encabezado con ícono, período arriba, color y conteo en cada número,
 * barras que crecen, cascada al entrar y respuesta al tocar. No toca los datos ni los gráficos. */
(function () {
  'use strict';
  const main = document.querySelector('.stats-main'); if (!main) return;
  const moving = () => document.documentElement.classList.contains('g7-move');
  const J = () => window.G7Juice || null, F = () => (J() && J()._fx) || null;
  const active = document.querySelector('.stats-nav .nav-link.active');
  const group = active && active.closest('.nav-group');
  const gc = group ? getComputedStyle(group).getPropertyValue('--gc').trim() : '';

  // Encabezado: ícono de la sección (el del menú) y el período como selector
  const head = main.querySelector('.page-header');
  if (head && active){
    if (gc) head.style.setProperty('--gc', gc);
    const ico = document.createElement('div'); ico.className = 'sx-ico'; const i = active.querySelector('i'); if (i) ico.innerHTML = `<i class="${i.className}"></i>`;
    head.prepend(ico);
    const per = [...document.querySelectorAll('.period-group .period-btn')];
    if (per.length){ const box = document.createElement('div'); box.className = 'sx-period';
      per.forEach(a => { const b = document.createElement('a'); b.href = a.href; b.textContent = a.textContent.trim(); if (a.classList.contains('active')) b.className = 'on'; box.append(b); });
      head.append(box); }
  }

  // Cada número toma el color de su ícono
  main.querySelectorAll('.mc').forEach(mc => {
    const ic = mc.querySelector('.mc-icon i'); if (!ic) return;
    const c = getComputedStyle(ic).color; if (c) mc.style.setProperty('--c', c);
  });

  // Conteo hacia arriba
  function count(el){
    const txt = el.textContent.trim(), m = txt.match(/^([^\d-]*)(-?[\d.,]+)(.*)$/); if (!m) return;
    const raw = m[2], dot = /\.\d{3}(?!\d)/.test(raw) && !/,\d{1,2}$/.test(raw), val = parseFloat(dot ? raw.replace(/\./g, '').replace(',', '.') : raw.replace(/,/g, ''));
    if (!(val > 0) || val > 1e9) return;
    const dec = (raw.split(/[.,]/)[1] || '').length && !dot && /[.,]\d{1,2}$/.test(raw) ? raw.split(/[.,]/).pop().length : 0;
    const t0 = performance.now(), dur = Math.min(1300, 500 + Math.log10(val + 1) * 250);
    const fmt = v => dec ? v.toFixed(dec) : Math.round(v).toLocaleString('es-AR');
    const step = now => { const u = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - u, 3); el.textContent = u >= 1 ? txt : m[1] + fmt(val * e) + m[3]; if (u < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step); setTimeout(() => { el.textContent = txt; }, dur + 400);
  }

  // Barras: crecen desde cero
  function bars(){
    main.querySelectorAll('.bar-fill').forEach((b, k) => { const w = b.style.width; if (!w) return; b.animate([{ width: '0%' }, { width: w }], { duration: 900, delay: 200 + k * 35, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }); });
  }

  // Entrada en cascada
  function enter(){
    if (!moving()) return;
    main.classList.add('sx-in');
    [...main.children].forEach((c, i) => c.style.setProperty('--i', Math.min(i, 10)));
    main.querySelectorAll('.mc').forEach((m, j) => m.style.setProperty('--j', Math.min(j, 14)));
    main.querySelectorAll('.mc-val').forEach(count);
    bars();
  }
  if (document.readyState === 'complete') enter(); else addEventListener('load', enter);

  // Tocar un número: salta y suena una nota según su posición
  const NOTES = [523.3, 587.3, 659.3, 784, 880, 1046.5, 1174.7];
  main.addEventListener('click', e => {
    const mc = e.target.closest('.mc'); if (!mc) return;
    const k = [...main.querySelectorAll('.mc')].indexOf(mc);
    if (moving()) mc.animate([{ scale: '1' }, { scale: '1.05' }, { scale: '.98' }, { scale: '1' }], { duration: 380, easing: 'ease-out' });
    const f = F(), j = J(); if (f && j && j.soundOn) try { f.tone(NOTES[k % NOTES.length], .14, { type: 'triangle', vol: .22 }); } catch (er) {}
  });
  // Cambiar de sección o período: el menú responde enseguida
  document.addEventListener('click', e => {
    const a = e.target.closest && e.target.closest('.stats-nav .nav-link, .sx-period a, .period-btn'); if (!a) return;
    const f = F(); if (f) f.play('tap', 0);
    if (moving()) a.animate([{ scale: '1' }, { scale: '.95' }, { scale: '1' }], { duration: 220 });
  });
})();
