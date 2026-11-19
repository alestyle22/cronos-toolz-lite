/* assets/profile-picker.js — Selector de herramientas o aptitudes (perfil, Comunidad y onboarding).
   PFPicker.mount(el, { groups, selected: [..], custom: true|false, placeholder, max, onChange }) → { get(): [..], set([..]) }
   groups: {Categoría: {Subcategoría: [..]}} (o {Categoría: [..]} de un solo nivel). Se navega con pestañas de categorías
   y, debajo, de subcategorías ("Todo" muestra la categoría entera agrupada); el buscador mira todo. Si custom, se puede
   escribir una que no esté. PFPicker.field() lo abre en un modal. Estilos: profile-edit.css (.pfp-*). Doc: toolz/COMUNIDAD.md */
(function () {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const norm = s => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  /** {Cat: {Sub: [..]}} o {Cat: [..]} → [{name, subs: [[sub, [..]], ..]}] */
  const tree = groups => Object.keys(groups || {}).map(name => { const v = groups[name]; return { name, subs: Array.isArray(v) ? [[name, v]] : Object.entries(v) }; });

  function mount(el, o) {
    const cats = tree(o.groups), max = o.max || 30;
    const all = [].concat(...cats.map(c => [].concat(...c.subs.map(s => s[1]))));
    let sel = [...(o.selected || [])], q = '', cat = cats[0] ? cats[0].name : '', sub = '';   // sub '' = Todo
    el.classList.add('pfp');
    el.innerHTML = `<div class="pfp-top"><label class="pfp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="text" placeholder="${esc(o.placeholder || 'Buscar…')}" maxlength="40" aria-label="${esc(o.placeholder || 'Buscar')}"></label><span class="pfp-n"></span></div>
      <div class="pfp-sel"></div>
      <div class="pfp-nav"><small class="pfp-lbl"><i class="fa-solid fa-layer-group"></i> Categorías</small><div class="pfp-tabs" role="tablist" aria-label="Categorías"></div>
        <div class="pfp-subs" role="tablist" aria-label="Subcategorías"></div></div>
      <div class="pfp-list"></div>`;
    const inp = el.querySelector('input'), selBox = el.querySelector('.pfp-sel'), nav = el.querySelector('.pfp-nav'), tabs = el.querySelector('.pfp-tabs'), subsEl = el.querySelector('.pfp-subs'), list = el.querySelector('.pfp-list'), nEl = el.querySelector('.pfp-n');
    const has = v => sel.some(x => norm(x) === norm(v));
    const count = arr => arr.filter(has).length;
    const badge = n => n ? ` <b>${n}</b>` : '';
    const chips = items => `<div class="pfp-chips">${items.map(v => `<button type="button" class="pfp-chip" data-v="${esc(v)}">${esc(v)}<i class="fa-solid fa-plus"></i></button>`).join('')}</div>`;
    function draw() {
      nEl.textContent = sel.length ? `${sel.length} elegida${sel.length === 1 ? '' : 's'}` : '';
      selBox.innerHTML = sel.length ? sel.map(v => `<button type="button" class="pfp-chip on" data-v="${esc(v)}" title="Quitar">${esc(v)}<i class="fa-solid fa-xmark"></i></button>`).join('') : `<span class="pfp-empty">${esc(o.empty || 'Todavía no elegiste ninguna.')}</span>`;
      const C = cats.find(c => c.name === cat) || cats[0];
      nav.hidden = !!q || cats.length < 2 && (!C || C.subs.length < 2);
      // categorías
      tabs.hidden = cats.length < 2;
      tabs.innerHTML = cats.map(c => `<button type="button" role="tab" aria-selected="${c === C}" class="${c === C ? 'on' : ''}" data-cat="${esc(c.name)}">${esc(c.name)}${badge(count([].concat(...c.subs.map(s => s[1]))))}</button>`).join('');
      // subcategorías de la elegida
      subsEl.hidden = !C || C.subs.length < 2;
      if (C && C.subs.length > 1) subsEl.innerHTML = `<button type="button" role="tab" aria-selected="${!sub}" class="${!sub ? 'on' : ''}" data-sub="">Todo</button>` +
        C.subs.map(([n, items]) => `<button type="button" role="tab" aria-selected="${sub === n}" class="${sub === n ? 'on' : ''}" data-sub="${esc(n)}">${esc(n)}${badge(count(items))}</button>`).join('');
      // opciones
      const nq = norm(q.trim());
      let html = '';
      if (q) {
        cats.forEach(c => c.subs.forEach(([n, items]) => { const it = items.filter(v => !has(v) && norm(v).includes(nq)); if (it.length) html += `<small class="pfp-g">${esc(c.name)}${n !== c.name ? ' › ' + esc(n) : ''}</small>` + chips(it); }));
        if (o.custom && nq && !all.some(v => norm(v) === nq) && !has(q.trim())) html = `<button type="button" class="pfp-add" data-add="${esc(q.trim())}"><i class="fa-solid fa-plus"></i> Agregar «${esc(q.trim())}»</button>` + html;
      } else if (C) {
        const show = sub ? C.subs.filter(s => s[0] === sub) : C.subs;
        show.forEach(([n, items]) => { const it = items.filter(v => !has(v)); if (it.length) html += (show.length > 1 ? `<small class="pfp-g">${esc(n)}</small>` : '') + chips(it); });
      }
      list.innerHTML = html || `<span class="pfp-empty">${q ? 'No hay coincidencias.' : 'Ya elegiste todas las de acá.'}</span>`;
    }
    function toggle(v) {
      if (has(v)) sel = sel.filter(x => norm(x) !== norm(v));
      else { if (sel.length >= max) { el.classList.remove('pfp-full'); void el.offsetWidth; el.classList.add('pfp-full'); return; } sel.push(v); }
      draw(); o.onChange && o.onChange(sel.slice());
    }
    el.addEventListener('click', e => {
      const c = e.target.closest('[data-v]'); if (c) { toggle(c.dataset.v); return; }
      const a = e.target.closest('[data-add]'); if (a) { toggle(a.dataset.add.replace(/[<>"]/g, '').slice(0, 40)); q = ''; inp.value = ''; draw(); inp.focus(); return; }
      const t = e.target.closest('[data-cat]'); if (t) { cat = t.dataset.cat; sub = ''; draw(); return; }
      const s = e.target.closest('[data-sub]'); if (s) { sub = s.dataset.sub; draw(); }
    });
    inp.addEventListener('input', () => { q = inp.value; draw(); });
    inp.addEventListener('keydown', e => {
      if (e.key !== 'Enter') return; e.preventDefault();
      const v = q.trim(); if (!v) return;
      const hit = all.find(x => norm(x) === norm(v)) || all.find(x => !has(x) && norm(x).includes(norm(v)));
      if (hit && !has(hit)) toggle(hit); else if (o.custom && !has(v)) toggle(v.replace(/[<>"]/g, '').slice(0, 40));
      q = ''; inp.value = ''; draw();
    });
    draw();
    return { get: () => sel.slice(), set: v => { sel = [...v]; draw(); } };
  }
  /** Versión compacta: muestra las elegidas y un botón que abre el selector en un modal (no alarga el formulario).
      PFPicker.field(el, { ...lo de mount, title, button }) → { get, set } */
  function field(el, o) {
    let sel = [...(o.selected || [])];
    el.classList.add('pfp-field');
    const draw = () => {
      const shown = sel.slice(0, 8), more = sel.length - shown.length;
      el.innerHTML = `<div class="pfp-fchips">${sel.length ? shown.map(v => `<span class="pfp-chip on">${esc(v)}</span>`).join('') + (more > 0 ? `<span class="pfp-more">+${more}</span>` : '') : `<span class="pfp-empty">${esc(o.empty || 'Todavía no elegiste ninguna.')}</span>`}</div>
        <button type="button" class="pfp-open"><i class="fa-solid ${sel.length ? 'fa-pen' : 'fa-plus'}"></i> ${esc(sel.length ? 'Cambiar' : (o.button || 'Elegir'))}</button>`;
    };
    function openModal() {
      const ov = document.createElement('div'); ov.className = 'pfp-ov'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', o.title || 'Elegir');
      ov.innerHTML = `<div class="pfp-modal"><header><h3>${esc(o.title || 'Elegir')}</h3>${o.help ? `<p>${esc(o.help)}</p>` : ''}<button type="button" class="pfp-x" data-x aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header><div class="pfp-mb"></div>
        <footer><button type="button" class="pfp-btn" data-x>Cancelar</button><button type="button" class="pfp-btn pri" data-ok><i class="fa-solid fa-check"></i> Listo</button></footer></div>`;
      document.body.appendChild(ov);
      const p = mount(ov.querySelector('.pfp-mb'), { ...o, selected: sel, onChange: null });
      const close = () => { ov.remove(); document.removeEventListener('keydown', onKey, true); el.querySelector('.pfp-open')?.focus(); };
      const onKey = e => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
      document.addEventListener('keydown', onKey, true);
      ov.addEventListener('click', e => {
        if (e.target === ov || e.target.closest('[data-x]')) close();
        else if (e.target.closest('[data-ok]')) { sel = p.get(); draw(); close(); o.onChange && o.onChange(sel.slice()); }
      });
      if (!matchMedia('(prefers-reduced-motion: reduce)').matches) ov.querySelector('.pfp-modal').animate([{ opacity: 0, transform: 'translateY(18px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 300, easing: 'cubic-bezier(.2,.8,.2,1)' });
      setTimeout(() => ov.querySelector('.pfp-search input')?.focus(), 50);
    }
    el.addEventListener('click', e => { if (e.target.closest('.pfp-open, .pfp-fchips')) openModal(); });
    draw();
    return { get: () => sel.slice(), set: v => { sel = [...v]; draw(); }, open: openModal };
  }
  window.PFPicker = { mount, field };
})();
