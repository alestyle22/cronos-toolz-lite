/* assets/promos-look.js — Movimiento y feedback de Promos CMS (promos/index.php), junto con promos-look.css.
   Ícono y "pulso" del encabezado, filtros activos marcados, grupos del sidebar con color, conteos que saltan,
   tarjetas que entran escalonadas con brillo que sigue al mouse, corazón que late. No toca promos.js: observa. */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const moving = () => document.documentElement.classList.contains('g7-move');
  const F = () => (window.G7Juice && window.G7Juice._fx) || null;
  const snd = (n, v) => { const f = F(); if (f && window.G7Juice.soundOn) try { f.play(n, v || 0); } catch (e) {} };

  function header(){
    const h = $('view-title'); if (!h || h.previousElementSibling && h.previousElementSibling.classList.contains('pl-ico')) return;
    const ico = document.createElement('span'); ico.className = 'pl-ico'; ico.innerHTML = '<i class="fa-solid fa-bullhorn"></i>';
    h.parentNode.insertBefore(ico, h);
    const res = $('pr-results'); const pulse = document.createElement('div'); pulse.className = 'pl-pulse'; pulse.id = 'pl-pulse';
    (res || h).after(pulse); drawPulse();
  }
  function drawPulse(){
    const box = $('pl-pulse'); if (!box || typeof items === 'undefined') return;
    const day = 864e5, now = new Date(), iso = d => d.toISOString().split('T')[0];
    const t = iso(now), wk = iso(new Date(now - 7 * day)), soon = iso(new Date(+now + 7 * day));
    const main = items.filter(p => p.type !== 'tarea' && p.type !== 'mailings');
    const act = main.filter(p => !(p.end && p.end < t)).length;
    const ending = main.filter(p => p.end && p.end >= t && p.end <= soon).length;
    const fresh = main.filter(p => p.start && p.start >= wk && p.start <= t).length;
    const pill = (rgb, ic, n, txt, cls) => `<span class="pl-pill ${cls || ''}" style="--pc-rgb:${rgb}"><i class="fa-solid ${ic}"></i><b>${n}</b>${txt}</span>`;
    box.innerHTML = pill('34,197,94', 'fa-circle', act, 'activos', 'live') + (ending ? pill('251,191,36', 'fa-hourglass-half', ending, 'terminan esta semana') : '') + (fresh ? pill('96,165,250', 'fa-wand-magic-sparkles', fresh, 'arrancaron esta semana') : '');
  }

  // sidebar: color por grupo
  function sidebar(){
    const cols = { estado: '#22c55e', tipo: '#a78bfa', proyecto: '#60a5fa' };
    document.querySelectorAll('#sidebar .sb-section').forEach(s => { const c = cols[s.textContent.trim().toLowerCase()]; if (c) s.style.setProperty('--sc', c); });
    document.getElementById('sidebar').addEventListener('click', e => { if (e.target.closest('.sb-btn')) snd('tap'); });
  }
  // conteos que saltan al cambiar
  function badges(){
    const mo = new MutationObserver(ms => ms.forEach(m => { const b = m.target.nodeType === 3 ? m.target.parentNode : m.target; if (!b || !b.classList || !b.classList.contains('badge') || !moving()) return; b.classList.remove('pl-bump'); void b.offsetWidth; b.classList.add('pl-bump'); }));
    document.querySelectorAll('#sidebar .badge').forEach(b => mo.observe(b, { childList: true, characterData: true, subtree: true }));
  }
  // filtros activos
  function filters(){
    const bar = $('filter-bar'); if (!bar) return;
    const clr = bar.querySelector('.clear-btn');
    const mark = () => {
      let n = 0; bar.querySelectorAll('.flt,.dflt').forEach(el => { const on = !!el.value; el.classList.toggle('pl-on', on); if (on) n++; });
      if (clr) { clr.classList.toggle('pl-on', n > 0); clr.innerHTML = '✕ Limpiar' + (n ? ` <span class="pl-nf">${n}</span>` : ''); }
    };
    bar.addEventListener('change', () => { mark(); snd('tap'); });
    if (clr) clr.addEventListener('click', () => setTimeout(mark, 0));
    mark();
  }
  // tarjetas: entrada escalonada, brillo que sigue al mouse
  function cards(){
    const c = $('item-container'); if (!c) return;
    let last = '';
    const enter = () => {
      const kids = [...c.querySelectorAll('.item-card')]; const sig = kids.length + ':' + c.className + ':' + (kids[0] && kids[0].getAttribute('data-click-1'));
      if (sig === last) return; last = sig;
      if (!moving()) return;
      kids.slice(0, 24).forEach((el, i) => el.animate([{ opacity: 0, transform: 'translateY(16px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 420, delay: i * 28, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
    };
    new MutationObserver(() => { enter(); drawPulse(); }).observe(c, { childList: true });
    enter();
    c.addEventListener('pointermove', e => { const k = e.target.closest('.grid-view .item-card'); if (!k) return; const r = k.getBoundingClientRect(); k.style.setProperty('--mx', (e.clientX - r.left) + 'px'); k.style.setProperty('--my', (e.clientY - r.top) + 'px'); });
    c.addEventListener('click', e => {
      const lb = e.target.closest('.like-btn'); if (lb) { lb.classList.remove('pl-pop'); void lb.offsetWidth; lb.classList.add('pl-pop'); const f = F(); if (f && window.G7Juice.soundOn) try { f.tone(880, .09, { type: 'triangle', vol: .18 }); f.tone(1320, .14, { type: 'sine', vol: .1, at: .05 }); } catch (er) {} return; }
      if (e.target.closest('.item-card')) snd('tap');
    }, true);
  }
  function boot(){ header(); sidebar(); badges(); filters(); cards(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
