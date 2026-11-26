/* assets/perfil.js — Página de perfil (perfil.php): pestañas (con #hash), buscador del equipo, copiar enlace, reconocer
   (cm-kudos.js), muro (comunidad.php › add_comment / delete_comment), hora local y números que cuentan. */
(function () {
  'use strict';
  const D = JSON.parse((document.getElementById('pf-data') || {}).textContent || '{}');
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const moving = () => document.documentElement.classList.contains('g7-move');
  const F = () => (window.G7Juice && window.G7Juice._fx) || null;
  const tap = () => { const f = F(); if (f) f.play('tap', 0); };
  const toast = (msg, icon) => { const f = F(); if (f) f.chip(msg, icon || 'fa-check', { x: innerWidth / 2, y: 120 }, '#22c55e'); else alert(msg); };
  const post = (body) => fetch('/toolz/comunidad.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(r => r.json());

  // Pestañas
  const tabs = $('pf-tabs');
  function show(t, push){
    const b = tabs.querySelector(`[data-t="${t}"]`); if (!b) return;
    tabs.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    document.querySelectorAll('.pf-panel').forEach(p => { const on = p.dataset.p === t; p.classList.toggle('on', on);
      if (on && moving()) [...p.querySelectorAll('.pf-card,.pf-post,.pf-mcard,.pf-kcard,.pf-wc')].slice(0, 24).forEach((el, i) => el.animate([{ opacity: 0, transform: 'translateY(12px)' }, { opacity: 1, transform: 'none' }], { duration: 380, delay: i * 30, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' })); });
    if (push) history.replaceState(null, '', '#' + t);
  }
  tabs.addEventListener('click', e => { const b = e.target.closest('button[data-t]'); if (b) { show(b.dataset.t, true); tap(); } });
  const h0 = location.hash.slice(1); if (h0) show(h0, false);

  // Equipo: buscador
  const q = $('pf-q');
  if (q) q.addEventListener('input', () => { const v = q.value.trim().toLowerCase(); document.querySelectorAll('#pf-people .pf-person').forEach(a => { a.hidden = v && !a.dataset.q.includes(v); }); });
  const cur = document.querySelector('#pf-people .pf-person.on'); if (cur) cur.scrollIntoView({ block: 'center' });

  // Copiar enlace
  const copy = $('pf-copy');
  if (copy) copy.addEventListener('click', async () => {
    const url = location.origin + '/toolz/perfil.php?u=' + D.uid;
    try { await navigator.clipboard.writeText(url); toast('Enlace copiado', 'fa-link'); } catch (e) { prompt('Copiá el enlace:', url); }
  });

  // Reconocer
  const kudos = () => window.CMKudos && Object.keys(D.kudos || {}).length && CMKudos.open({ to: D.uid, team: D.team, kudos: D.kudos, me: D.me, onDone: () => setTimeout(() => location.reload(), 900) });
  ['pf-kudos', 'pf-kudos2'].forEach(id => { const b = $(id); if (b) b.addEventListener('click', kudos); });
  if (new URLSearchParams(location.search).get('reconocer') && !D.self) setTimeout(kudos, 300);   // llega desde el chat u otra página

  // Hora local de la persona
  const clk = document.querySelector('.pf-clock'), tz = document.querySelector('.pf-loc[data-tz]');
  if (clk && tz) { const tick = () => { try { clk.textContent = new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', timeZone: tz.dataset.tz }); } catch (e) {} }; tick(); setInterval(tick, 20000); }

  // Números que cuentan
  if (moving()) document.querySelectorAll('.pf-stat b').forEach((b, k) => {
    const raw = b.dataset.n || '', n = parseInt(raw, 10), suf = raw.replace(/^[0-9]+/, ''); if (!n || n < 2) return;
    const t0 = performance.now() + k * 70, dur = 900; b.textContent = '0' + suf;
    const step = t => { const p = Math.min(1, Math.max(0, (t - t0) / dur)); b.textContent = Math.round(n * (1 - Math.pow(1 - p, 3))) + suf; if (p < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  });

  // Muro
  const wall = $('pf-wall'), win = $('pf-wall-in'), wsend = $('pf-wall-send');
  const ago = () => 'ahora';
  if (wsend) wsend.addEventListener('click', async () => {
    const body = win.value.trim(); if (!body) return win.focus();
    wsend.disabled = true;
    try {
      const d = await post({ action: 'add_comment', profile_id: D.uid, parent_id: null, body });
      if (d.status !== 'success') throw new Error(d.error || 'No se pudo publicar');
      const c = d.comment, nm = c.display_name || c.username;
      const av = c.avatar ? `<img class="pf-av" src="${esc(c.avatar)}" alt="" style="width:34px;height:34px">` : `<span class="pf-av pf-av-l" style="width:34px;height:34px;font-size:14px;background:#ff6600">${esc(nm.charAt(0).toUpperCase())}</span>`;
      const el = document.createElement('div'); el.className = 'pf-wc'; el.dataset.id = c.id;
      el.innerHTML = `<a href="/toolz/perfil.php?u=${+c.author_id}">${av}</a><div><b><a href="/toolz/perfil.php?u=${+c.author_id}">${esc(nm)}</a> <small>${ago()}</small></b><p>${esc(c.body).replace(/\n/g, '<br>')}</p></div><button type="button" class="pf-wc-del" title="Borrar"><i class="fa-solid fa-trash-can"></i></button>`;
      const empty = $('pf-wall-empty'); if (empty) empty.remove();
      wall.prepend(el); win.value = '';
      $('pf-wall-n').textContent = wall.querySelectorAll('.pf-wc').length;
      if (moving()) el.animate([{ opacity: 0, transform: 'translateY(-10px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 420, easing: 'cubic-bezier(.34,1.56,.64,1)' });
      const f = F(); if (f) f.play('pop', 0);
    } catch (er) { toast(er.message, 'fa-triangle-exclamation'); }
    wsend.disabled = false;
  });
  if (win) win.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) wsend.click(); });
  if (wall) wall.addEventListener('click', async e => {
    const b = e.target.closest('.pf-wc-del'); if (!b) return;
    const row = b.closest('.pf-wc'); if (!confirm('¿Borrar este mensaje del muro?')) return;
    const d = await post({ action: 'delete_comment', comment_id: +row.dataset.id });
    if (d.status === 'success') { row.remove(); $('pf-wall-n').textContent = wall.querySelectorAll('.pf-wc').length; } else toast('No se pudo borrar', 'fa-triangle-exclamation');
  });
})();
