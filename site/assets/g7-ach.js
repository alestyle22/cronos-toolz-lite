/* assets/g7-ach.js — Aviso de logros: cada tanto (y al cambiar de página) pregunta si se desbloqueó algo y lo festeja
 * con una medalla que cae desde arriba, el color del nivel, la XP ganada y confeti. Doc: toolz/LOGROS.md */
(function () {
  'use strict';
  if (window.__g7ach) return; window.__g7ach = true;
  const KEY = 'g7-ach-at', EVERY = 3 * 60 * 1000;
  const J = () => window.G7Juice || null, F = () => (J() && J()._fx) || null;
  const moving = () => document.documentElement.classList.contains('g7-move');
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  async function check(force){
    let last = 0; try { last = +sessionStorage.getItem(KEY) || 0; } catch (e) {}
    if (!force && Date.now() - last < EVERY) return;
    try { sessionStorage.setItem(KEY, String(Date.now())); } catch (e) {}
    try {
      const r = await fetch('/toolz/achievements_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ACH': '1' }, credentials: 'same-origin', body: JSON.stringify({ action: 'check' }) });
      const j = await r.json(); if (j && j.new && j.new.length) queue(j.new);
    } catch (e) {}
  }
  let Q = [], busy = false;
  function queue(list){ Q = Q.concat(list.slice(0, 6)); if (list.length > 6) Q.push({ more: list.length - 6 }); if (!busy) next(); }
  function next(){
    const a = Q.shift(); if (!a){ busy = false; return; } busy = true;
    if (a.more){ show({ name: `¡Y ${a.more} logros más!`, text: 'Miralos todos en tu vitrina', icon: 'fa-trophy', color: '#f5c542', tier_name: '', xp: 0, tier: 3 }); return; }
    show(a);
  }
  function show(a){
    const d = document.createElement('a'); d.className = 'g7a-pop'; d.href = '/toolz/logros.php'; d.style.setProperty('--tc', a.color);
    d.innerHTML = `<span class="g7a-medal"><i class="fa-solid ${esc(a.icon)}"></i></span><span class="g7a-txt"><small>${a.tier_name ? '¡Logro desbloqueado! · ' + esc(a.tier_name) : '¡Logro desbloqueado!'}</small><b>${esc(a.name)}</b><em>${esc(a.text)}</em></span>${a.xp ? `<span class="g7a-xp">+${a.xp} XP</span>` : ''}`;
    document.body.append(d);
    const f = F(), j = J();
    if (j) j.celebrate(a.tier >= 3 ? 'big' : a.tier === 2 ? 'medium' : 'subtle');
    if (f && j && j.soundOn) try { [784, 988, 1175, 1568].forEach((fr, i) => f.tone(fr, .18, { type: 'triangle', vol: .22, at: i * .07 })); } catch (e) {}
    if (moving()){
      d.animate([{ transform: 'translate(-50%,-140%) scale(.7)', opacity: 0 }, { transform: 'translate(-50%,8%) scale(1.04)', opacity: 1, offset: .55 }, { transform: 'translate(-50%,0) scale(1)', opacity: 1 }], { duration: 650, easing: 'cubic-bezier(.2,.9,.3,1.2)', fill: 'both' });
      d.querySelector('.g7a-medal').animate([{ rotate: '-200deg', scale: '.3' }, { rotate: '15deg', scale: '1.2', offset: .7 }, { rotate: '0deg', scale: '1' }], { duration: 900, easing: 'cubic-bezier(.34,1.56,.64,1)' });
    }
    setTimeout(() => {
      const out = () => { d.remove(); setTimeout(next, 250); };
      if (moving()) d.animate([{ transform: 'translate(-50%,0)', opacity: 1 }, { transform: 'translate(-50%,-140%)', opacity: 0 }], { duration: 380, easing: 'ease-in', fill: 'forwards' }).onfinish = out; else out();
    }, 4200);
  }
  window.G7Ach = { check: () => check(true), show };
  const boot = () => setTimeout(() => check(false), 2500);
  if (document.readyState === 'complete') boot(); else addEventListener('load', boot);
})();
