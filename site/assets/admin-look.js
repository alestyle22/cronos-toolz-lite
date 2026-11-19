/* assets/admin-look.js — Detalles del panel Admin (2026-11-14): buscador del menú, encabezado de sección con ícono y
 * grupo, y entrada animada al cambiar de sección. No toca la lógica de admin_ui.js: solo mira qué sección está activa. */
(function () {
  'use strict';
  const nav = document.querySelector('.adm-nav'); if (!nav) return;
  const items = [...nav.querySelectorAll('.adm-nav-item')];

  // ── Grupo de cada ítem (el separador anterior) ──
  // Un color por grupo del menú (se hereda al encabezado de cada sección)
  const GROUP_C = { 'general': 'var(--admx-1)', 'personas y accesos': 'var(--admx-2)', 'plataforma': 'var(--admx-3)', 'red cronos': 'var(--admx-7)', 'datos y registros': 'var(--admx-4)' };
  let group = '', gc = '';
  for (const el of nav.children) {
    if (el.classList.contains('adm-nav-sep')) { group = el.textContent.trim(); gc = GROUP_C[group.toLowerCase()] || 'var(--admx-5)'; el.style.setProperty('--c', gc); }
    else if (el.classList.contains('adm-nav-item')) { el.dataset.group = group; if (gc) el.style.setProperty('--c', gc); }
  }
  // Números que en realidad son palabras ("Apagada", "Activa"): otra escala
  const words = root => (root || document).querySelectorAll('.adm-stat-val').forEach(v => v.classList.toggle('admx-word', !/^[\s\d.,$€%+\-−]/.test(v.textContent.trim().charAt(0) || '0')));
  new MutationObserver(() => words()).observe(document.querySelector('.adm-content') || document.body, { childList: true, subtree: true });
  words();

  // ── Encabezado: ícono del menú + grupo arriba del título ──
  function decorate(sec) {
    if (!sec || sec.dataset.admx) return;
    sec.dataset.admx = '1';
    const id = sec.id.replace(/^sec-/, '');
    const it = nav.querySelector(`.adm-nav-item[data-sec="${id}"]`);
    const title = sec.querySelector('.adm-title');
    if (!it || !title) return;
    const holder = title.parentElement;
    const sub = holder.querySelector(':scope > .adm-sub');
    const ico = it.querySelector('i');
    const head = document.createElement('div'); head.className = 'admx-head';
    const badge = document.createElement('div'); badge.className = 'admx-ico';
    const c = it.style.getPropertyValue('--c'); if (c) head.style.setProperty('--c', c);
    if (ico) { const i = document.createElement('i'); i.className = ico.className; badge.append(i); }
    const txt = document.createElement('div');
    if (it.dataset.group) { const c = document.createElement('div'); c.className = 'admx-crumb'; c.textContent = it.dataset.group; txt.append(c); }
    title.before(head); txt.append(title);
    if (sub && sub.previousElementSibling === head) txt.append(sub);
    else if (!sub && holder !== sec && holder.nextElementSibling?.classList.contains('adm-sub')) {
      // Título en una fila con botones y el subtítulo debajo de la fila (Logs, Topbar): se suma al encabezado
      const s2 = holder.nextElementSibling; txt.append(s2); holder.style.marginBottom = '26px';
    }
    head.append(badge, txt);
    if (holder === sec) { const w = document.createElement('div'); w.className = 'admx-head-wrap'; head.before(w); w.append(head); }
  }

  // ── Entrada animada: cada hijo directo y los números, escalonados ──
  function enter(sec) {
    decorate(sec);
    sec.classList.remove('admx-in');
    [...sec.children].forEach((c, i) => c.style.setProperty('--i', Math.min(i, 8)));
    sec.querySelectorAll('.adm-stat').forEach((s, j) => s.style.setProperty('--j', Math.min(j, 10)));
    void sec.offsetWidth;
    sec.classList.add('admx-in');
  }
  let current = null;
  const check = () => {
    const sec = document.querySelector('.adm-section.active');
    if (sec && sec !== current) { current = sec; enter(sec); }
  };
  new MutationObserver(check).observe(document.querySelector('.adm-content') || document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
  check();
  // Los números que llegan después (contenido, accesos) también entran con su escalón
  const late = new MutationObserver(muts => {
    for (const m of muts) for (const n of m.addedNodes) if (n.nodeType === 1 && n.closest('.admx-in')) {
      const st = n.matches('.adm-stat') ? [n] : [...n.querySelectorAll('.adm-stat')];
      st.forEach((s, j) => s.style.setProperty('--j', Math.min(j, 10)));
    }
  });
  ['content-stats-grid', 'acc-overview'].forEach(id => { const el = document.getElementById(id); if (el) late.observe(el, { childList: true, subtree: true }); });

  // ── Buscador del menú ──
  const q = document.getElementById('admx-q'), empty = document.getElementById('admx-empty');
  const norm = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  items.forEach(it => { it.dataset.label = it.textContent.trim(); it.dataset.norm = norm(it.dataset.label + ' ' + (it.dataset.group || '') + ' ' + (it.dataset.sec || '')); });
  function filter() {
    const v = norm(q.value.trim());
    let shown = 0;
    items.forEach(it => {
      const ok = !v || it.dataset.norm.includes(v);
      it.classList.toggle('admx-hide', !ok);
      if (ok) shown++;
    });
    nav.querySelectorAll('.adm-nav-sep').forEach(sep => {
      let n = sep.nextElementSibling, any = false;
      while (n && !n.classList.contains('adm-nav-sep')) { if (n.classList.contains('adm-nav-item') && !n.classList.contains('admx-hide')) { any = true; break; } n = n.nextElementSibling; }
      sep.classList.toggle('admx-hide', !!v && !any);
    });
    if (empty) empty.classList.toggle('on', !!v && !shown);
  }
  if (q) {
    q.addEventListener('input', filter);
    q.addEventListener('keydown', e => {
      if (e.key === 'Enter') { const first = items.find(it => !it.classList.contains('admx-hide')); if (first) { first.click(); q.value = ''; filter(); q.blur(); } e.preventDefault(); }
      else if (e.key === 'Escape') { q.value = ''; filter(); q.blur(); }
      else if (e.key === 'ArrowDown') { const first = items.find(it => !it.classList.contains('admx-hide')); if (first) { first.focus(); e.preventDefault(); } }
    });
    document.addEventListener('keydown', e => {
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
      const t = e.target; if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
      e.preventDefault(); q.focus(); q.select();
    });
  }
  // Flechas dentro del menú
  nav.addEventListener('keydown', e => {
    if (!e.target.classList.contains('adm-nav-item') || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) return;
    const vis = items.filter(it => !it.classList.contains('admx-hide')), i = vis.indexOf(e.target);
    const nx = vis[i + (e.key === 'ArrowDown' ? 1 : -1)];
    if (nx) nx.focus(); else if (e.key === 'ArrowUp' && q) q.focus();
    e.preventDefault();
  });

  // El ítem activo queda a la vista dentro del menú
  const keepVisible = () => { const a = nav.querySelector('.adm-nav-item.active'); if (a && nav.scrollHeight > nav.clientHeight) { const r = a.getBoundingClientRect(), n = nav.getBoundingClientRect(); if (r.top < n.top + 40 || r.bottom > n.bottom - 20) a.scrollIntoView({ block: 'nearest' }); } };
  setTimeout(keepVisible, 300);
})();
