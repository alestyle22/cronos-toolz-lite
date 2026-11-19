/* assets/user-look.js — Detalles de Mi configuración (2026-11-14): color por grupo del menú, encabezado de sección con
 * ícono, entrada animada y la foto nueva en la tarjeta de presentación. No toca la lógica de user-config.js. */
(function () {
  'use strict';
  const nav = document.querySelector('.cfg-nav'); if (!nav) return;
  const GROUP_C = { 'cuenta': 'var(--ucx-2)', 'personalización': 'var(--ucx-3)', 'sistema': 'var(--ucx-4)' };
  let group = '', gc = '';
  for (const el of nav.children) {
    if (el.classList.contains('cfg-nav-sep')) { group = el.textContent.trim(); gc = GROUP_C[group.toLowerCase()] || 'var(--ucx-5)'; el.style.setProperty('--c', gc); }
    else if (el.classList.contains('cfg-nav-item')) { el.dataset.group = group; if (gc) el.style.setProperty('--c', gc); }
  }
  const itemFor = sec => [...nav.querySelectorAll('.cfg-nav-item')].find(b => b.getAttribute('data-click-1') === 's:' + sec);

  function decorate(sec) {
    if (!sec || sec.dataset.ucx) return;
    sec.dataset.ucx = '1';
    const it = itemFor(sec.id.replace(/^sec-/, ''));
    const title = sec.querySelector(':scope > .cfg-section-title');
    if (!it || !title) return;
    const sub = title.nextElementSibling && title.nextElementSibling.classList.contains('cfg-section-sub') ? title.nextElementSibling : null;
    const head = document.createElement('div'); head.className = 'ucx-head';
    const c = it.style.getPropertyValue('--c'); if (c) head.style.setProperty('--c', c);
    const badge = document.createElement('div'); badge.className = 'ucx-ico';
    const ico = it.querySelector('i'); if (ico) { const i = document.createElement('i'); i.className = ico.className; badge.append(i); }
    const txt = document.createElement('div');
    if (it.dataset.group) { const cr = document.createElement('div'); cr.className = 'ucx-crumb'; cr.textContent = it.dataset.group; txt.append(cr); }
    title.before(head); txt.append(title); if (sub) txt.append(sub);
    head.append(badge, txt);
  }
  function enter(sec) {
    decorate(sec);
    sec.classList.remove('ucx-in');
    [...sec.children].forEach((ch, i) => ch.style.setProperty('--i', Math.min(i, 8)));
    void sec.offsetWidth;
    sec.classList.add('ucx-in');
  }
  let current = null;
  const check = () => { const s = document.querySelector('.cfg-section.active'); if (s && s !== current) { current = s; enter(s); } };
  new MutationObserver(check).observe(document.querySelector('.cfg-content') || document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
  check();

  // Pestañas del selector de temas (Oscuros, Claros, Vidrio, Especiales)
  document.addEventListener('click', e => {
    const tab = e.target.closest && e.target.closest('.cfg-themes-tab'); if (!tab) return;
    const g = tab.dataset.g, box = tab.closest('.cfg-row') || document;
    box.querySelectorAll('.cfg-themes-tab').forEach(t => { const on = t === tab; t.classList.toggle('on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    box.querySelectorAll('.cfg-themes-panel').forEach(p => { p.hidden = p.dataset.g !== g; });
    if (window.G7Sound) G7Sound('tap', 60);
  });

  // En el celular, el ítem activo queda a la vista en la tira de pastillas
  const showActive = () => { const a = nav.querySelector('.cfg-nav-item.active'); if (a && nav.scrollWidth > nav.clientWidth) a.scrollIntoView({ block: 'nearest', inline: 'center' }); };
  nav.addEventListener('click', () => setTimeout(showActive, 30));
  window.addEventListener('load', () => setTimeout(showActive, 100));

  // La foto y el nombre nuevos se copian a la tarjeta de presentación y al menú
  const avSrc = document.getElementById('cfg-avatar-img');
  const syncAv = () => {
    const el = document.getElementById('cfg-avatar-img'); if (!el || el.tagName !== 'IMG' || !el.src) return;
    document.querySelectorAll('.ucx-av').forEach(a => {
      if (a.tagName === 'IMG') { a.src = el.src; return; }
      const img = document.createElement('img'); img.className = a.className; img.alt = ''; img.src = el.src; a.replaceWith(img);
    });
  };
  if (avSrc && avSrc.parentElement) new MutationObserver(syncAv).observe(avSrc.parentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['src'] });
  const nameIn = document.getElementById('cfg-display-name');
  if (nameIn) document.addEventListener('click', e => {
    if (!e.target.closest('[data-on-click="cfgSaveName"]')) return;
    const v = nameIn.value.trim(); if (v) setTimeout(() => document.querySelectorAll('.ucx-name').forEach(n => { n.textContent = v; }), 400);
  });
})();
