/* assets/logros.js — Vitrina de logros (logros.php). Pide el estado a achievements_api.php y dibuja pestañas por
 * categoría, tarjetas con medalla y progreso, el resumen por nivel y el ranking. */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const D = JSON.parse(($('lg-data') || {}).textContent || '{}');
  const moving = () => document.documentElement.classList.contains('g7-move');
  const J = () => window.G7Juice || null, F = () => (J() && J()._fx) || null;
  let S = null, cat = 'all', filt = 'all', query = '';
  const norm = s => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const fmt = n => Number(n).toLocaleString('es-AR');

  async function load(){
    try {
      const r = await fetch('/toolz/achievements_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ACH': '1' }, credentials: 'same-origin', body: JSON.stringify({ action: 'state' }) });
      S = await r.json(); if (!S.ok) throw new Error(S.error || 'Error');
    } catch (e) { $('lg-grid').innerHTML = `<div class="lg-loading">${esc(e.message)}</div>`; return; }
    tabs(); tally(); draw(); rank();
    const med = S.items.reduce((a, it) => a + it.tier, 0), all = S.items.reduce((a, it) => a + it.max, 0);
    $('lg-total').innerHTML = '<b>' + med + '</b> de ' + all + ' medallas';
    let qt = 0; $('lg-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { query = norm(e.target.value.trim()); draw(); }, 150); });
    $('lg-seg').onclick = e => { const b = e.target.closest('button'); if (!b) return; filt = b.dataset.f; $('lg-seg').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); draw(); const f = F(); if (f) f.play('tap', 0); };
  }
  function tabs(){
    const counts = {}; S.items.forEach(it => { counts[it.cat] = counts[it.cat] || [0, 0]; counts[it.cat][1]++; if (it.tier) counts[it.cat][0]++; });
    const all = [S.items.filter(i => i.tier).length, S.items.length];
    $('lg-tabs').innerHTML = `<button type="button" class="lg-tab on" data-c="all"><i class="fa-solid fa-trophy"></i> Todos <small>${all[0]}/${all[1]}</small></button>` +
      Object.entries(S.cats).filter(([k]) => counts[k]).map(([k, c]) => `<button type="button" class="lg-tab" data-c="${k}" style="--cc:${c.color}"><i class="fa-solid ${c.icon}"></i> ${esc(c.name)} <small>${counts[k][0]}/${counts[k][1]}</small></button>`).join('');
    $('lg-tabs').onclick = e => { const b = e.target.closest('.lg-tab'); if (!b) return; cat = b.dataset.c; $('lg-tabs').querySelectorAll('.lg-tab').forEach(x => x.classList.toggle('on', x === b)); draw(); const f = F(); if (f) f.play('tap', 0); };
  }
  function tally(){
    const n = [0, 0, 0, 0, 0]; S.items.forEach(it => { for (let t = 1; t <= it.tier; t++) n[t]++; });
    $('lg-tally').innerHTML = [1, 2, 3, 4].map(t => `<div class="lg-t" style="--tc:${S.tiers[t].color}"><span class="lg-mini"><i class="fa-solid fa-medal"></i></span><b data-n="${n[t]}">${n[t]}</b><small>${S.tiers[t].name}</small></div>`).join('');
  }
  function card(it){
    const tc = it.tier ? S.tiers[it.tier].color : null, cc = (S.cats[it.cat] || {}).color || 'var(--g7-accent)';
    const pips = it.max > 1 ? `<div class="lg-pips">${Array.from({ length: it.max }, (_, i) => `<i style="--pc:${S.tiers[i + 1].color}" class="${i < it.tier ? 'on' : ''}" title="${esc(S.tiers[i + 1].name)}"></i>`).join('')}</div>` : '';
    const lvl = it.tier ? (it.max > 1 ? S.tiers[it.tier].name : 'Logrado') : 'Sin desbloquear';
    const goal = it.goal !== null && it.goal !== undefined;
    const date = it.tier && it.dates && it.dates[it.tier] ? new Date(String(it.dates[it.tier]).replace(' ', 'T')).toLocaleDateString('es-AR', { day: 'numeric', month: 'short' }) : '';
    return `<article class="lg-a${it.tier ? ' got' : ''}${!goal ? ' max' : ''}" style="--tc:${tc || 'var(--g7-bg-5)'};--cc:${cc}" data-key="${esc(it.key)}">
      <div class="lg-medal"><i class="fa-solid ${esc(it.icon)}"></i>${!goal ? '<span class="lg-crown"><i class="fa-solid fa-crown"></i></span>' : ''}</div>
      <div class="lg-a-body">
        <div class="lg-a-top"><b>${esc(it.name)}</b><span class="lg-lvl">${esc(lvl)}</span></div>
        <p>${goal ? esc(it.text) : '¡Completaste todos los niveles!'}</p>
        ${goal ? `<div class="lg-prog"><span><i style="width:${it.pct}%"></i></span><em>${fmt(it.value)} / ${fmt(it.goal)}</em></div>` : `<div class="lg-prog done"><em>${fmt(it.value)} ${esc(it.unit)}</em></div>`}
        <div class="lg-a-foot">${pips}${date ? `<small>${esc(date)}</small>` : ''}</div>
      </div>
    </article>`;
  }
  function draw(){
    const list = S.items.filter(it => (cat === 'all' || it.cat === cat) && (filt === 'all' || (filt === 'got' ? it.tier > 0 : it.goal !== null && it.goal !== undefined)) && (!query || norm(it.name + ' ' + it.text).includes(query)))
      .sort((a, b) => (b.tier > 0) - (a.tier > 0) || b.tier - a.tier || b.pct - a.pct);
    $('lg-grid').innerHTML = list.map(card).join('') || '<div class="lg-loading">Nada por acá todavía.</div>';
    if (moving()) [...$('lg-grid').children].forEach((el, i) => el.animate([{ opacity: 0, transform: 'translateY(14px) scale(.96)' }, { opacity: 1, transform: 'none' }], { duration: 420, delay: Math.min(i, 16) * 35, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
    // barras: crecen
    if (moving()) $('lg-grid').querySelectorAll('.lg-prog i').forEach((b, k) => b.animate([{ width: '0%' }, { width: b.style.width }], { duration: 900, delay: 200 + Math.min(k, 16) * 35, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
  }
  function rank(){
    const r = D.rank || [];
    $('lg-rank').innerHTML = r.map((u, i) => `<li class="${+u.id === +D.me ? 'me' : ''}" data-g7-user="${+u.id}"><span class="lg-pos">${i < 3 ? ['🥇', '🥈', '🥉'][i] : i + 1}</span>${u.avatar ? `<img src="${esc(u.avatar)}" alt="">` : `<span class="lg-av">${esc(String(u.name || '?').charAt(0).toUpperCase())}</span>`}<b>${esc(u.name)}</b><em>${u.n} <i class="fa-solid fa-medal"></i></em></li>`).join('') || '<li class="lg-empty">Todavía nadie tiene logros.</li>';
  }
  // tocar una medalla desbloqueada: gira y suena
  document.addEventListener('click', e => {
    const a = e.target.closest('.lg-a.got'); if (!a) return;
    const m = a.querySelector('.lg-medal'); if (moving()) m.animate([{ rotate: '0deg', scale: '1' }, { rotate: '360deg', scale: '1.15', offset: .7 }, { rotate: '360deg', scale: '1' }], { duration: 700, easing: 'cubic-bezier(.34,1.56,.64,1)' });
    const f = F(), j = J(); if (f && j && j.soundOn) try { f.tone(1175, .12, { type: 'triangle', vol: .22 }); f.tone(1568, .2, { type: 'sine', vol: .1, at: .06 }); } catch (er) {}
  });
  load();
})();
