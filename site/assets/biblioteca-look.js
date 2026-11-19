/* assets/biblioteca-look.js — Biblioteca con estética nueva (links/index.php), va después de links.js. No cambia lo que hace:
   portada con números, encabezados de categoría con color e ícono, portadas automáticas para los enlaces sin imagen
   (degradé por sitio + inicial), entrada escalonada y corazón que estalla. Estilos: biblioteca-look.css */
(function () {
  'use strict';
  document.body.classList.add('blx');
  const moving = () => document.documentElement.classList.contains('g7-move');
  const FX = () => (window.G7Juice && window.G7Juice._fx) || null;
  const hash = s => { let h = 2166136261; for (const ch of String(s)) { h ^= ch.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; };
  const hue = s => hash(s) % 360;
  const ICONS = [[/dise|arte|grafic|foto|imag/i, 'fa-palette'], [/desarr|codig|dev|program|git/i, 'fa-code'], [/market|redes|social|ads|pauta/i, 'fa-bullhorn'], [/video|youtube|cine/i, 'fa-film'],
    [/herram|tool|util/i, 'fa-screwdriver-wrench'], [/doc|manual|guia|ayuda|soporte/i, 'fa-book-open'], [/drive|archivo|nube|cloud/i, 'fa-cloud'], [/stock|banco|recurso/i, 'fa-box-archive'], [/ia|ai|intelig/i, 'fa-wand-magic-sparkles'], [/general/i, 'fa-star']];
  const iconFor = n => (ICONS.find(([r]) => r.test(n)) || [0, 'fa-folder'])[1];

  // ── Portada con números ──
  const head = document.querySelector('.lb-head');
  const stats = document.createElement('div'); stats.className = 'blx-stats';
  head.after(stats);
  function paintStats(){
    const withNote = links.filter(l => (notes[l.id] || '').trim() || (l.public_note || '').trim()).length;
    const cats = categories.filter(c => links.some(l => l.category_id == c.id)).length;
    const S = [['fa-link', '#60a5fa', links.length, 'Recursos'], ['fa-folder-open', '#a78bfa', cats, 'Categorías'], ['fa-heart', '#ef4444', favorites.size, 'Favoritos'], ['fa-note-sticky', '#f59e0b', withNote, 'Con notas']];
    stats.innerHTML = S.map(([i, c, n, l]) => `<div class="blx-stat" style="--c:${c}"><i class="fa-solid ${i}"></i><b>${n}</b><small>${l}</small></div>`).join('');
  }
  const h1 = head.querySelector('h1'); if (h1 && !h1.querySelector('.blx-ico')) h1.insertAdjacentHTML('afterbegin', '<span class="blx-ico"><i class="fa-solid fa-book-bookmark"></i></span>');

  // ── Decorar lo que dibuja links.js ──
  function decorate(){
    document.querySelectorAll('.category-section').forEach((sec, k) => {
      const t = sec.querySelector('.section-title'); if (!t) return;
      const name = t.textContent, h = hue(name);
      sec.style.setProperty('--cc', `hsl(${h} 75% 60%)`);
      if (!t.querySelector('.blx-cico')) t.insertAdjacentHTML('afterbegin', `<span class="blx-cico"><i class="fa-solid ${iconFor(name)}"></i></span>`);
    });
    document.querySelectorAll('.lb-card').forEach(card => {
      const wrap = card.closest('.link-card-wrap'), cover = card.querySelector('.lb-cover'); if (!cover || cover.dataset.blx) return; cover.dataset.blx = '1';
      const name = wrap ? wrap.dataset.nameRaw || '' : '', dom = (card.querySelector('.lb-domain') || {}).textContent || name, h = hue(name + '|' + dom.trim());
      cover.style.setProperty('--h1', h); cover.style.setProperty('--h2', (h + 50) % 360);
      const ph = cover.querySelector('.ph');
      if (ph) ph.innerHTML = `<span class="blx-mono">${(name.trim()[0] || '?').toUpperCase()}</span><span class="blx-dom">${dom.trim() || 'Enlace'}</span>`;
      // la imagen falló: queda la portada automática
      const img = cover.querySelector('img'); if (img) img.addEventListener('error', () => cover.classList.add('noimg'), { once: true });
    });
    if (moving()) [...document.querySelectorAll('.link-card-wrap:not(.hidden)')].slice(0, 40).forEach((el, i) => el.animate([{ opacity: 0, transform: 'translateY(16px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 460, delay: i * 30, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
    paintStats();
  }
  const _render = window.renderAll;
  if (typeof _render === 'function') window.renderAll = function () { const r = _render.apply(this, arguments); decorate(); return r; };
  // favorito: corazón que estalla
  const _fav = window.toggleFavorite;
  if (typeof _fav === 'function') window.toggleFavorite = function (id, btn) {
    const r = _fav.apply(this, arguments);
    setTimeout(() => { paintStats(); const on = btn && btn.classList.contains('on'); if (!btn) return; btn.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.45)' }, { transform: 'scale(1)' }], { duration: 420, easing: 'cubic-bezier(.34,1.56,.64,1)' });
      if (on && moving()) { const b = btn.getBoundingClientRect(), f = FX(); if (f) f.confetti(16, { x: b.left + b.width / 2, y: b.top + b.height / 2 }, ['#ef4444', '#f472b6', '#fb7185', '#ffffff'], { power: .45, size: .6, spread: 2.4 }); } }, 30);
    return r;
  };
  decorate();
})();
