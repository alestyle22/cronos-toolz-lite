/* assets/cm-kudos.js — Ventana "Reconocer a alguien" (Comunidad y Perfil). window.CMKudos.open({ to, team, kudos, me, onDone }).
   Elegís a la persona (si no viene), el reconocimiento y en pocas palabras por qué; se guarda con comunidad.php › give_kudos,
   aparece en el feed, le llega un aviso y suma en su perfil. Estilos en perfil.css y comunidad-plus.css (.cmk-*). */
(function () {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const F = () => (window.G7Juice && window.G7Juice._fx) || null;
  const moving = () => document.documentElement.classList.contains('g7-move');
  function avatar(u, s){ return u.avatar ? `<img src="${esc(u.avatar)}" alt="" style="width:${s}px;height:${s}px">` : `<span style="width:${s}px;height:${s}px">${esc((u.name || '?').charAt(0).toUpperCase())}</span>`; }
  function open(opt){
    const team = (opt.team || []).filter(u => +u.id !== +opt.me);
    let to = opt.to ? team.find(u => +u.id === +opt.to) || null : null, badge = '';
    const bg = document.createElement('div'); bg.className = 'cmk-bg';
    bg.innerHTML = `<div class="cmk" role="dialog" aria-modal="true" aria-label="Reconocer a alguien">
      <button type="button" class="cmk-x" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
      <div class="cmk-head"><span class="cmk-trophy">🏆</span><div><b>Reconocer a alguien</b><small>Contale al equipo qué hizo bien. Le llega un aviso y suma en su perfil.</small></div></div>
      <div class="cmk-step"><label>¿A quién?</label><div class="cmk-who"></div></div>
      <div class="cmk-step"><label>¿Por qué lo reconocés?</label><div class="cmk-badges">${Object.entries(opt.kudos || {}).map(([k, [e, n]]) => `<button type="button" data-b="${esc(k)}"><span>${esc(e)}</span>${esc(n)}</button>`).join('')}</div></div>
      <div class="cmk-step"><label>En pocas palabras</label><textarea maxlength="280" rows="3" placeholder="Ej: Resolvió el pedido de la landing en tiempo récord y lo explicó clarito."></textarea><small class="cmk-cnt">0 / 280</small></div>
      <div class="cmk-foot"><button type="button" class="cmk-cancel">Cancelar</button><button type="button" class="cmk-send" disabled><i class="fa-solid fa-award"></i> Reconocer</button></div>
    </div>`;
    document.body.appendChild(bg);
    const $ = s => bg.querySelector(s), ta = $('textarea'), send = $('.cmk-send');
    const who = () => {
      if (to) { $('.cmk-who').innerHTML = `<div class="cmk-picked">${avatar(to, 34)}<b>${esc(to.name)}</b>${opt.to ? '' : '<button type="button" class="cmk-change">Cambiar</button>'}</div>`; return; }
      $('.cmk-who').innerHTML = `<input type="search" class="cmk-q" placeholder="Buscar persona…" autocomplete="off"><div class="cmk-list">${team.map(u => `<button type="button" data-u="${u.id}">${avatar(u, 26)}<span>${esc(u.name)}</span></button>`).join('')}</div>`;
      const q = $('.cmk-q'); q.focus();
      q.oninput = () => { const v = q.value.trim().toLowerCase(); bg.querySelectorAll('.cmk-list button').forEach(b => { b.hidden = v && !b.textContent.toLowerCase().includes(v); }); };
    };
    const check = () => { send.disabled = !(to && badge && ta.value.trim().length >= 3); $('.cmk-cnt').textContent = ta.value.length + ' / 280'; };
    const close = () => { bg.classList.add('out'); setTimeout(() => bg.remove(), 200); document.removeEventListener('keydown', onKey); };
    const onKey = e => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    bg.addEventListener('click', e => {
      if (e.target === bg || e.target.closest('.cmk-x,.cmk-cancel')) return close();
      const u = e.target.closest('[data-u]'); if (u) { to = team.find(x => +x.id === +u.dataset.u); who(); check(); const f = F(); if (f) f.play('tap', 0); return; }
      if (e.target.closest('.cmk-change')) { to = null; who(); check(); return; }
      const b = e.target.closest('[data-b]'); if (b) { badge = b.dataset.b; bg.querySelectorAll('[data-b]').forEach(x => x.classList.toggle('on', x === b)); check(); const f = F(); if (f) f.play('tap', 0); if (!ta.value) ta.focus(); }
    });
    ta.addEventListener('input', check);
    send.addEventListener('click', async () => {
      send.disabled = true; send.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando…';
      try {
        const r = await fetch('/toolz/comunidad.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'give_kudos', user_id: to.id, badge, reason: ta.value.trim() }) });
        const d = await r.json();
        if (d.status !== 'success') throw new Error(d.error || 'No se pudo guardar');
        const rect = send.getBoundingClientRect(), f = F();
        if (f) { if (moving()) f.confetti(70, { x: rect.left + rect.width / 2, y: rect.top }, ['#f5c542', '#ec4899', '#22c55e', '#60a5fa', '#ffffff'], { power: 1.1, spread: 2.8 }); if (window.G7Juice.soundOn) [523, 659, 784, 1047, 1319].forEach((hz, i) => f.tone(hz, .28, { type: 'triangle', vol: .14, at: i * .07 })); f.chip('¡Reconocimiento enviado!', 'fa-award', { x: rect.left + rect.width / 2, y: rect.top }, '#ec4899'); }
        close(); if (opt.onDone) opt.onDone(d.post);
      } catch (er) { send.disabled = false; send.innerHTML = '<i class="fa-solid fa-award"></i> Reconocer'; let m = bg.querySelector('.cmk-err'); if (!m) { m = document.createElement('div'); m.className = 'cmk-err'; send.parentNode.before(m); } m.textContent = er.message; }
    });
    who(); check();
    if (moving()) bg.querySelector('.cmk').animate([{ opacity: 0, transform: 'translateY(18px) scale(.96)' }, { opacity: 1, transform: 'none' }], { duration: 380, easing: 'cubic-bezier(.34,1.56,.64,1)' });
  }
  window.CMKudos = { open };
})();
