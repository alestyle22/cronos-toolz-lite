/* assets/dash-hero.js — Banner del Inicio vivo (index.php). Paralaje con el mouse, sol/luna que se tocan
   (festejo o lluvia de estrellas), autos con bocina, saludo letra por letra y reloj en la fecha. Estilos: dash-hero.css */
(function () {
  'use strict';
  const head = document.querySelector('.d3-head'), scene = head && head.querySelector('.d3-scene');
  if (!head || !scene) return;
  const moving = () => document.documentElement.classList.contains('g7-move');
  const J = () => window.G7Juice || null, F = () => (J() && J()._fx) || null;
  const sound = () => { const j = J(); return !!(j && j.soundOn && F()); };

  // paralaje: el mouse sobre el banner corre las capas (las de adelante más)
  let raf = 0, tx = 0, ty = 0;
  head.addEventListener('pointermove', e => {
    if (!moving()) return; const r = head.getBoundingClientRect();
    tx = ((e.clientX - r.left) / r.width - .5) * 2; ty = ((e.clientY - r.top) / r.height - .5) * 2;
    if (!raf) raf = requestAnimationFrame(() => { raf = 0; scene.style.setProperty('--px', tx.toFixed(3)); scene.style.setProperty('--py', ty.toFixed(3)); });
  });
  head.addEventListener('pointerleave', () => { scene.style.setProperty('--px', 0); scene.style.setProperty('--py', 0); });

  const restart = (el, cls, ms) => { el.classList.remove(cls); void el.getBoundingClientRect(); el.classList.add(cls); setTimeout(() => el.classList.remove(cls), ms); };
  scene.addEventListener('click', e => {
    const sun = e.target.closest('.sc-sun');
    if (sun) {
      const f = F(), o = { x: e.clientX, y: e.clientY };
      restart(sun, 'pop', 750);
      if (sun.dataset.sc === 'moon') {
        restart(scene, 'sc-shower', 3200);
        if (sound()) [1568, 1976, 2349, 2637].forEach((hz, i) => f.tone(hz, .35, { type: 'sine', vol: .1, at: i * .12 }));
        if (f && moving()) f.confetti(26, o, ['#fef3c7', '#e0e7ff', '#a5b4fc', '#ffffff'], { power: .6, size: .6, spread: 2.6 });
      } else {
        restart(sun, 'fast', 2600);
        if (sound()) [523, 659, 784, 1047].forEach((hz, i) => f.tone(hz, .3, { type: 'triangle', vol: .16, at: i * .07 }));
        if (f && moving()) f.confetti(40, o, ['#fde047', '#facc15', '#fb923c', '#fff7d6', '#f97316'], { power: .8, spread: 2.8 });
      }
      return;
    }
    const car = e.target.closest('.sc-car');
    if (car) {
      restart(car, 'honk', 500);
      if (sound()) { const f = F(); f.tone(392, .12, { type: 'square', vol: .07 }); f.tone(330, .16, { type: 'square', vol: .07, at: .15 }); }
      const f = F(); if (f) f.chip('¡Bip bip!', 'fa-car-side', { x: e.clientX, y: e.clientY }, '#38bdf8');
    }
  });

  // saludo: letra por letra
  const h1 = head.querySelector('h1');
  if (h1 && moving()) {
    let i = 0;
    const split = node => [...node.childNodes].forEach(n => {
      if (n.nodeType === 3) { const frag = document.createDocumentFragment(); [...n.textContent].forEach(ch => { const s = document.createElement('span'); s.className = 'dh-ch'; s.textContent = ch; s.style.setProperty('--i', i++); frag.appendChild(s); }); n.replaceWith(frag); }
      else if (n.nodeType === 1 && n.tagName !== 'SPAN') split(n);
    });
    split(h1);   // el nombre (span con degradado) entra entero, para no romper su brillo
    h1.querySelectorAll('.dh-ch').forEach((s, k) => s.animate([{ opacity: 0, transform: 'translateY(.45em) rotate(6deg)' }, { opacity: 1, transform: 'none' }], { duration: 520, delay: 80 + k * 26, easing: 'cubic-bezier(.34,1.56,.64,1)', fill: 'backwards' }));
    const nm = h1.querySelector('span'); if (nm) nm.animate([{ opacity: 0, transform: 'scale(.6) translateY(.3em)', filter: 'blur(6px)' }, { opacity: 1, transform: 'none', filter: 'none' }], { duration: 700, delay: 80 + i * 26, easing: 'cubic-bezier(.34,1.56,.64,1)', fill: 'backwards' });
  }

  // reloj en la línea de la fecha
  const date = head.querySelector('.d3-date');
  if (date) {
    const c = document.createElement('span'); c.className = 'dh-clock'; date.appendChild(c);
    const tick = () => { const d = new Date(); c.innerHTML = String(d.getHours()).padStart(2, '0') + '<i>:</i>' + String(d.getMinutes()).padStart(2, '0'); };
    tick(); setInterval(tick, 15000);
  }

  // secciones: aparecen al bajar (las que ya se ven quedan como están)
  if (moving() && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver(es => es.forEach(en => { if (en.isIntersecting) { en.target.classList.add('dh-on'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('main.d3 > section, main.d3 > .d3-sec').forEach(sec => { if (sec.getBoundingClientRect().top > innerHeight) { sec.classList.add('dh-rev'); io.observe(sec); } });
  }
  // accesos rápidos: se inclinan hacia el mouse
  document.addEventListener('pointermove', e => {
    const t = e.target.closest && e.target.closest('.d3-tiles .tc'); if (!t || !moving()) return;
    const r = t.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
    t.style.setProperty('--ry', (x * 10).toFixed(1) + 'deg'); t.style.setProperty('--rx', (-y * 10).toFixed(1) + 'deg');
  });
  document.addEventListener('pointerout', e => { const t = e.target.closest && e.target.closest('.d3-tiles .tc'); if (t && !t.contains(e.relatedTarget)) { t.style.removeProperty('--rx'); t.style.removeProperty('--ry'); } });
})();
