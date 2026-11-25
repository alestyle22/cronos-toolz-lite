/* assets/presence-hints.js — Avisa el estado de una persona al escribirle y al asignarle algo (datos de G7Frames, presence_lib.php). ESTADOS.md */
(function () {
  'use strict';
  if (window.G7Away) return;
  const SKIP = ['available', 'remote', 'focus'];   // está trabajando: no hace falta avisar
  /** [emoji, texto] si conviene avisar, o null */
  const away = window.G7Away = id => { const F = window.G7Frames, b = F && F.badgeOf(+id); return b && b[3] && !SKIP.includes(b[3]) && +id !== F.me ? [b[0], b[1]] : null; };
  window.G7AwayTag = id => { const a = away(id); return a ? ` <em class="g7-away-tag">${a[0]} ${a[1].split(' · ')[0].replace(/[&<>"]/g, '')}</em>` : ''; };

  // Chat completo y burbuja: cartel arriba de donde se escribe. Cada uno avisa con quién se está hablando ('g7:peer')
  const peers = {};
  function note(compId) {
    const comp = document.getElementById(compId), old = document.getElementById(compId + '-away'), p = peers[compId], a = p && p.id ? away(p.id) : null;
    if (!comp || !a || comp.hidden) { if (old) old.remove(); return; }
    const el = old || document.createElement('div');
    if (!old) { el.id = compId + '-away'; el.className = 'g7-away-note'; el.setAttribute('role', 'status'); }
    if (el.nextElementSibling !== comp) comp.before(el);
    const txt = `${p.name.split(' ')[0]}: ${a[1]}`;
    if (el.dataset.t !== a[0] + txt) { el.dataset.t = a[0] + txt; el.innerHTML = '<b></b><span></span>'; el.firstChild.textContent = a[0]; el.lastChild.textContent = txt; }
  }
  document.addEventListener('g7:peer', e => { peers[e.detail.comp] = e.detail; note(e.detail.comp); });
  document.addEventListener('g7:badges', () => Object.keys(peers).forEach(note));

  // Listas de personas: el estado al lado del nombre
  const SEL = '#pt-asg,#pt-ap-u,#rq-asg,#fm-asg,select[data-people]';
  const deco = e => { const s = e.target.closest && e.target.closest(SEL); if (!s) return; for (const o of s.options) { if (!o.dataset.n) o.dataset.n = o.textContent; const a = /^\d+$/.test(o.value) && away(o.value); o.textContent = o.dataset.n + (a ? ` · ${a[0]} ${a[1].split(' · ')[0]}` : ''); } };
  document.addEventListener('pointerdown', deco, true); document.addEventListener('focusin', deco);

  // Al asignar (tickets, solicitudes, tareas): aviso después de guardar, sin frenar
  const told = new Map();
  function tell(id) {
    const a = away(id); if (!a || Date.now() - (told.get(id + a[1]) || 0) < 60000) return; told.set(id + a[1], Date.now());
    const nm = names[id] || 'Esa persona', t = document.createElement('div');
    t.className = 'g7-away-toast'; t.setAttribute('role', 'status'); t.innerHTML = '<b></b><span></span>'; t.firstChild.textContent = a[0];
    t.lastChild.textContent = `${nm} está en "${a[1].split(' · ')[0]}"${a[1].includes(' · ') ? ' (' + a[1].split(' · ').slice(1).join(' · ') + ')' : ''}. Quizás no lo vea enseguida.`;
    document.body.append(t); setTimeout(() => t.remove(), 7000);
  }
  let names = {};
  const idsOf = (url, b, j) => {
    if (/portal_api\.php/.test(url)) { if (b.action === 'ticket_assign' || b.action === 'ticket_approve') { const r = j.assigned || {}; return [r.id || r.uid || r.user || b.user || b.assignee]; } return []; }
    if (/trabajo\/api\.php|^api\.php/.test(url) && /^(ticket_update|request_update|req_update)$/.test(b.action || '')) return [b.assignee_id];
    if (/tareas_api\.php/.test(url) && Array.isArray(b.assignees)) return b.assignees;
    if (/chat\/api\.php|^api\.php/.test(url) && /^(ticket_from_msg|task_from_msg|qa_ticket|qa_task)$/.test(b.action || '')) return [b.assignee];
    return [];
  };
  const of = window.fetch;
  window.fetch = function (input, init) {
    const p = of.apply(this, arguments);
    try {
      const url = typeof input === 'string' ? input : (input && input.url) || '';
      if (init && typeof init.body === 'string' && /assign|"user"/.test(init.body) && /(portal_api|tareas_api|api)\.php/.test(url)) {
        const b = JSON.parse(init.body);
        p.then(r => r.ok ? r.clone().json() : null).then(j => {
          if (!j || j.error || j.ok === false) return;
          names = Object.fromEntries(((typeof W !== 'undefined' && W.users) || (typeof TK !== 'undefined' && TK.users) || (window.CCX && Object.values(CCX.S.users)) || []).map(u => [u.id, u.name]));
          document.querySelectorAll(SEL).forEach(s => [...s.options].forEach(o => { if (/^\d+$/.test(o.value) && !names[o.value]) names[o.value] = o.dataset.n || o.textContent; }));
          idsOf(url, b, j).map(Number).filter(Boolean).forEach(tell);
        }).catch(() => {});
      }
    } catch (e) {}
    return p;
  };
})();
