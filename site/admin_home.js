/* admin_home.js — Sección "Inicio y accesos" del panel Admin.
   Edita g7_config 'home_config' (ver home_lib.php): bloques de accesos rápidos, encabezado y secciones del Inicio.
   Usa esc(), $id(), accApi(), toggle() y admToast() del panel, y FA_ICONS_FLAT de admin.php. */
'use strict';

// Admin › Clientes ahora es una página aparte
if (location.hash === '#clientes') location.replace('/toolz/clientes.php');

const HM = { cfg: null, base: '', meta: null, draft: null, editIdx: -1 };
const HM_COLORS = ['#ff6600', '#fbbf24', '#4ade80', '#2dd4bf', '#60a5fa', '#a78bfa', '#ec4899', '#f87171', '#a1a1aa'];
const HM_SEC_ICON = { alert: 'fa-circle-exclamation', recent: 'fa-clock-rotate-left', tiles: 'fa-bolt', more: 'fa-grip', panels: 'fa-table-columns', favs: 'fa-star', popular: 'fa-fire' };
const hmRgb = (h) => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16)).join(',');
const hmHrefOk = (h) => /^(\/(?!\/)|https?:\/\/)/i.test(h.trim());

async function hmLoad() {
  if (HM.cfg && hmDirty()) return;   // no pisar lo que se está editando
  try {
    HM.meta = await accApi('home_get');
    hmSet(HM.meta.config);
  } catch (e) { $id('hm-body').innerHTML = '<div class="hm-empty">No se pudo cargar la configuración del Inicio.</div>'; }
}
function hmSet(cfg) { HM.cfg = JSON.parse(JSON.stringify(cfg)); HM.base = JSON.stringify(HM.cfg); hmRender(); }
const hmDirty = () => !!HM.cfg && JSON.stringify(HM.cfg) !== HM.base;
function hmTouch(full = true) {
  if (full) hmRender(); else hmRenderPreview();
  const d = hmDirty();
  $id('hm-save').disabled = !d;
  $id('hm-dirty').classList.toggle('on', d);
}

// ── Dibujo ────────────────────────────────────────────────────
function hmRender() {
  const c = HM.cfg, S = HM.meta.sections;
  const used = new Set(c.tiles.map(t => t.href));
  const freeMods = HM.meta.modules.filter(m => !used.has(m.href));
  $id('hm-body').innerHTML = `<div class="hm-layout"><div>
    <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-heading"></i> Encabezado</div></div><div class="adm-card-body">
      <div class="hm-line"><div><b>Saludo</b><small>"Buen día, Nombre" arriba de todo.</small></div>${toggle('hm-greeting', c.greeting)}</div>
      <div class="hm-line"><div><b>Buscador de herramientas</b><small>El campo para filtrar los bloques y herramientas (atajo /).</small></div>${toggle('hm-search', c.search)}</div>
      <div class="hm-line" style="display:block"><b>Texto bajo el saludo</b><small>Vacío = automático: cuántas promos vencen esta semana.</small>
        <input class="adm-input" id="hm-subtitle" maxlength="160" style="margin-top:8px" placeholder="Ej: Esta semana cerramos la campaña de verano" value="${esc(c.subtitle)}"></div>
    </div></div>

    <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-bolt"></i> Accesos rápidos <span class="acc-muted" style="font-weight:500">${c.tiles.length}</span></div></div><div class="adm-card-body">
      <p class="hm-card-sub">Arrastra para cambiar el orden. Cada bloque puede ir a una herramienta de la plataforma o a cualquier link. Las herramientas solo las ven quienes tienen permiso para abrirlas.</p>
      <div class="hm-list" data-list="tiles">${c.tiles.length ? c.tiles.map(hmTileRow).join('') : '<div class="hm-empty">No hay bloques. Agrega uno con los botones de abajo.</div>'}</div>
      <div class="hm-add">
        <button class="btn btn-primary btn-sm" type="button" data-a="new"><i class="fa-solid fa-plus"></i> Nuevo acceso</button>
        ${freeMods.length ? `<select class="adm-select" id="hm-addmod" style="width:auto;height:30px;font-size:11.5px"><option value="">+ Agregar una herramienta…</option>${freeMods.map(m => `<option value="${esc(m.id)}">${esc(m.label)}</option>`).join('')}</select>` : ''}
      </div>
    </div></div>

    <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-layer-group"></i> Secciones del Inicio</div></div><div class="adm-card-body">
      <p class="hm-card-sub">Prende, apaga y ordena lo que aparece debajo del encabezado. Los títulos se pueden cambiar.</p>
      <div class="hm-list" data-list="sections">${c.sections.map((s, i) => hmSecRow(s, i, S[s.id])).join('')}</div>
    </div></div>
  </div>
  <div class="hm-prev"><div class="adm-label">Vista previa</div><div class="hm-prev-box" id="hm-prev"></div></div></div>`;
  hmRenderPreview();
}
function hmAud(t) {
  if (t.audience === 'admins') return '<span class="hm-tag adm">Solo admins</span>';
  if (t.audience === 'teams') {
    const n = t.teams.map(id => HM.meta.teams.find(x => x.id === id)?.name).filter(Boolean);
    return `<span class="hm-tag tm" title="${esc(n.join(', '))}">${n.length === 1 ? esc(n[0]) : n.length + ' equipos'}</span>`;
  }
  return '';
}
function hmArrows(i, n) {
  return `<span class="hm-mini"><button type="button" data-a="up" ${i ? '' : 'disabled'} aria-label="Subir"><i class="fa-solid fa-chevron-up"></i></button><button type="button" data-a="down" ${i < n - 1 ? '' : 'disabled'} aria-label="Bajar"><i class="fa-solid fa-chevron-down"></i></button></span>`;
}
function hmTileRow(t, i) {
  return `<div class="hm-row${t.on ? '' : ' off'}" data-i="${i}">
    <span class="hm-grip" data-grip title="Arrastra para ordenar"><i class="fa-solid fa-grip-vertical"></i></span>${hmArrows(i, HM.cfg.tiles.length)}
    <span class="hm-ic" style="background:rgba(${hmRgb(t.color)},.15);color:${esc(t.color)}"><i class="fa-solid ${esc(t.icon)}"></i></span>
    <div class="hm-txt"><b>${esc(t.name)}${t.badge ? ` <span class="hm-tag" style="background:${esc(t.color)};color:#000">${esc(t.badge)}</span>` : ''}</b><small>${esc(t.href)}${t.newtab ? ' · pestaña nueva' : ''}</small></div>
    ${hmAud(t)}
    <span class="hm-tg" title="${t.on ? 'Visible' : 'Oculto'}">${toggle('hm-ton-' + i, t.on)}</span>
    <button class="btn-icon" type="button" data-a="edit" title="Editar"><i class="fa-solid fa-pen"></i></button>
    <button class="btn-icon" type="button" data-a="del" title="Quitar"><i class="fa-regular fa-trash-can"></i></button>
  </div>`;
}
function hmSecRow(s, i, meta) {
  const c = HM.cfg;
  const subs = s.id === 'panels' ? `<div class="hm-subs">
      <label><input type="checkbox" data-panel="panel_soon" ${c.panel_soon ? 'checked' : ''}> Vencen pronto</label>
      <label><input type="checkbox" data-panel="panel_summary" ${c.panel_summary ? 'checked' : ''}> Números</label>
      <label><input type="checkbox" data-panel="panel_activity" ${c.panel_activity ? 'checked' : ''}> Actividad del equipo</label></div>` : '';
  return `<div class="hm-row${s.on ? '' : ' off'}" data-i="${i}">
    <span class="hm-grip" data-grip title="Arrastra para ordenar"><i class="fa-solid fa-grip-vertical"></i></span>${hmArrows(i, c.sections.length)}
    <span class="hm-ic" style="background:var(--g7-bg-5);color:var(--g7-text-3)"><i class="fa-solid ${HM_SEC_ICON[s.id] || 'fa-square'}"></i></span>
    <div class="hm-sec-t"><b>${esc(meta.label)}</b><small>${esc(meta.desc)}</small>
      ${meta.title ? `<input class="adm-input" data-sec-title="${i}" maxlength="40" placeholder="Título: ${esc(meta.label)}" value="${esc(s.title)}" aria-label="Título de ${esc(meta.label)}">` : ''}${subs}</div>
    <span class="hm-tg" title="${s.on ? 'Visible' : 'Oculta'}">${toggle('hm-son-' + i, s.on)}</span>
  </div>`;
}
function hmTileHtml(t, tag = 'div', attrs = '') {
  const rgb = hmRgb(t.color);
  return `<${tag} class="hm-tc" ${attrs}><div class="hm-tc-art" style="background:linear-gradient(145deg,rgba(${rgb},.18),rgba(${rgb},.04))">
      <div class="hm-tc-glow" style="background:radial-gradient(circle at 60% 30%,rgba(${rgb},.35),transparent 70%)"></div>
      <i class="fa-solid ${esc(t.icon)}" style="color:${esc(t.color)}"></i>${t.badge ? `<span class="hm-tc-badge" style="background:rgba(${rgb},.9)">${esc(t.badge)}</span>` : ''}</div>
    <div class="hm-tc-b"><b>${esc(t.name || 'Sin nombre')}</b><small>${esc(t.desc)}</small></div></${tag}>`;
}
function hmRenderPreview() {
  const c = HM.cfg, S = HM.meta.sections, box = $id('hm-prev'); if (!box) return;
  const tilesOn = c.sections.find(s => s.id === 'tiles')?.on;
  const vis = c.tiles.map((t, i) => [t, i]).filter(([t]) => t.on);
  const title = (id) => c.sections.find(s => s.id === id)?.title || S[id].label;
  box.innerHTML = `${c.greeting ? '<div style="font-size:18px;font-weight:700;letter-spacing:-.02em">Buen día, <span style="color:var(--g7-accent)">Nombre</span></div>' : ''}
    <div style="font-size:11.5px;color:var(--g7-text-4);margin:4px 0 16px">${esc(c.subtitle || 'Texto automático sobre las promos de la semana')}</div>
    ${tilesOn ? `<div class="hm-prev-h"><i class="fa-solid fa-bolt" style="color:var(--g7-accent)"></i> ${esc(title('tiles'))}</div>
      ${vis.length ? `<div class="hm-tiles">${vis.map(([t, i]) => hmTileHtml(t, 'button', `type="button" data-a="edit" data-i="${i}" title="Editar"`)).join('')}</div>` : '<div class="hm-empty">No hay bloques visibles.</div>'}`
      : '<div class="hm-empty">La sección de accesos rápidos está apagada.</div>'}
    <div class="hm-order"><div class="adm-label" style="margin:6px 0 4px">Orden de las secciones</div>
      ${c.sections.map(s => `<div class="${s.on ? 'on' : ''}"><i class="fa-solid ${s.on ? HM_SEC_ICON[s.id] : 'fa-eye-slash'}"></i>${esc(title(s.id))}${s.on ? '' : ' · oculta'}</div>`).join('')}</div>
    <p class="hm-card-sub" style="margin:14px 0 0">Así lo ve un admin. El resto ve solo los bloques que le corresponden.</p>`;
}

// ── Acciones ──────────────────────────────────────────────────
function hmMove(list, from, to) {
  const a = HM.cfg[list]; if (to < 0 || to >= a.length || from === to) return;
  a.splice(to, 0, a.splice(from, 1)[0]); hmTouch();
}
function hmNewTile(extra = {}) {
  const color = HM_COLORS[HM.cfg.tiles.length % HM_COLORS.length];
  return { id: '', href: '', icon: 'fa-link', name: '', desc: '', color, badge: '', newtab: false, on: true, audience: 'all', teams: [], ...extra };
}
function hmOnClick(e) {
  const a = e.target.closest('[data-a]'); if (!a || !$id('sec-home').contains(a)) return;
  const row = a.closest('.hm-row'), list = row?.parentElement.dataset.list, i = +(a.dataset.i ?? row?.dataset.i ?? -1);
  switch (a.dataset.a) {
    case 'up': hmMove(list, i, i - 1); break;
    case 'down': hmMove(list, i, i + 1); break;
    case 'new': hmEdit(-1); break;
    case 'edit': hmEdit(i); break;
    case 'del': {
      const t = HM.cfg.tiles[i];
      if (!confirm(`¿Quitar "${t.name}" de los accesos rápidos?`)) return;
      HM.cfg.tiles.splice(i, 1); hmTouch(); admToast('Bloque quitado. Guarda para aplicarlo.');
      break;
    }
  }
}
function hmOnChange(e) {
  const el = e.target, c = HM.cfg;
  if (el.id === 'hm-greeting' || el.id === 'hm-search') { c[el.id.slice(3)] = el.checked; hmTouch(false); return; }
  if (el.id === 'hm-addmod') {
    const m = HM.meta.modules.find(x => x.id === el.value); if (!m) return;
    c.tiles.push(hmNewTile({ id: m.id, href: m.href, icon: m.icon, name: m.label, desc: m.desc }));
    hmTouch(); admToast(`"${m.label}" agregado al final. Guarda para aplicarlo.`); return;
  }
  let m = el.id.match(/^hm-(t|s)on-(\d+)$/);
  if (m) { (m[1] === 't' ? c.tiles : c.sections)[+m[2]].on = el.checked; hmTouch(); return; }
  if (el.dataset.panel) { c[el.dataset.panel] = el.checked; hmTouch(false); }
}
function hmOnInput(e) {
  const el = e.target;
  if (el.id === 'hm-subtitle') { HM.cfg.subtitle = el.value; hmTouch(false); }
  else if (el.dataset.secTitle !== undefined) { HM.cfg.sections[+el.dataset.secTitle].title = el.value; hmTouch(false); }
}

// Arrastrar filas para ordenar (con mouse o con el dedo, desde la manija)
function hmSetupDrag(root) {
  let D = null;
  root.addEventListener('pointerdown', e => {
    const g = e.target.closest('[data-grip]'); if (!g || e.button !== 0) return;
    const row = g.closest('.hm-row'), listEl = row.parentElement;
    e.preventDefault();
    D = { row, listEl, list: listEl.dataset.list, from: +row.dataset.i };
    row.classList.add('dragging'); g.setPointerCapture?.(e.pointerId);
  });
  root.addEventListener('pointermove', e => {
    if (!D) return;
    const rows = [...D.listEl.querySelectorAll('.hm-row')].filter(r => r !== D.row);
    const before = rows.find(r => { const b = r.getBoundingClientRect(); return e.clientY < b.top + b.height / 2; });
    if (before) { if (D.row.nextSibling !== before) D.listEl.insertBefore(D.row, before); } else if (D.listEl.lastElementChild !== D.row) D.listEl.appendChild(D.row);
  });
  const end = () => {
    if (!D) return;
    const to = [...D.listEl.querySelectorAll('.hm-row')].indexOf(D.row), d = D; D = null;
    d.row.classList.remove('dragging');
    if (to !== d.from) hmMove(d.list, d.from, to); else hmRender();
  };
  root.addEventListener('pointerup', end); root.addEventListener('pointercancel', end);
}

// ── Editor de un bloque ───────────────────────────────────────
function hmEdit(i) {
  HM.editIdx = i;
  HM.draft = JSON.parse(JSON.stringify(i >= 0 ? HM.cfg.tiles[i] : hmNewTile()));
  const t = HM.draft, mods = HM.meta.modules, dlg = $id('hm-dlg');
  const modSel = mods.find(m => m.href === t.href)?.id || '';
  dlg.innerHTML = `<form class="hm-dlg-in" method="dialog" novalidate>
    <div class="hm-dlg-h"><b id="hm-dlg-t">${i >= 0 ? 'Editar acceso' : 'Nuevo acceso'}</b><button class="btn-icon" type="button" data-x aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="hm-dlg-b"><div>
      <div class="hm-f"><label class="adm-label" for="hd-mod">Lleva a</label>
        <select class="adm-select" id="hd-mod"><option value="">Un link propio (otra página o sitio)</option>${mods.map(m => `<option value="${esc(m.id)}"${m.id === modSel ? ' selected' : ''}>${esc(m.label)}</option>`).join('')}</select></div>
      <div class="hm-f" id="hd-f-href"><label class="adm-label" for="hd-href">Link *</label><input class="adm-input" id="hd-href" maxlength="500" placeholder="/toolz/… o https://…" value="${esc(t.href)}">
        <div class="hm-err">Pon una dirección que empiece con / (de la plataforma) o con https://</div></div>
      <div class="adm-form-grid" style="margin-bottom:0">
        <div class="hm-f" id="hd-f-name"><label class="adm-label" for="hd-name">Nombre *</label><input class="adm-input" id="hd-name" maxlength="40" value="${esc(t.name)}"><div class="hm-err">Ponele un nombre</div></div>
        <div class="hm-f"><label class="adm-label" for="hd-badge">Etiqueta</label><input class="adm-input" id="hd-badge" maxlength="8" placeholder="IA, NUEVO…" value="${esc(t.badge)}"></div>
      </div>
      <div class="hm-f"><label class="adm-label" for="hd-desc">Descripción</label><input class="adm-input" id="hd-desc" maxlength="80" placeholder="Una línea corta" value="${esc(t.desc)}"></div>
      <div class="hm-f"><label class="adm-label" for="hd-icq">Ícono</label><input class="adm-input" id="hd-icq" placeholder="Buscar ícono (en inglés: chart, user, star…)">
        <div class="hm-icons" id="hd-icons"></div></div>
      <div class="hm-f"><span class="adm-label">Color</span><div class="hm-sw" id="hd-sw"></div></div>
      <div class="hm-f"><label class="hm-chk"><input type="checkbox" id="hd-newtab" ${t.newtab ? 'checked' : ''}> Abrir en una pestaña nueva</label></div>
      <div class="hm-f"><label class="adm-label" for="hd-aud">Quién lo ve</label>
        <select class="adm-select" id="hd-aud">
          <option value="all"${t.audience === 'all' ? ' selected' : ''}>Todos (los que pueden abrirlo)</option>
          <option value="admins"${t.audience === 'admins' ? ' selected' : ''}>Solo admins</option>
          <option value="teams"${t.audience === 'teams' ? ' selected' : ''}${HM.meta.teams.length ? '' : ' disabled'}>Algunos equipos${HM.meta.teams.length ? '' : ' (no hay equipos)'}</option>
        </select>
        <div class="hm-teams" id="hd-teams"${t.audience === 'teams' ? '' : ' hidden'}>${HM.meta.teams.map(x => `<label><input type="checkbox" value="${x.id}" ${t.teams.includes(x.id) ? 'checked' : ''}> ${esc(x.name)}</label>`).join('')}</div>
        <div class="hm-err" id="hd-teams-err" style="display:none">Elige al menos un equipo</div></div>
    </div>
    <div class="hm-dprev"><div class="adm-label">Así se ve</div><div id="hd-prev"></div></div></div>
    <div class="hm-dlg-f"><button class="btn btn-ghost" type="button" data-x>Cancelar</button><button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> ${i >= 0 ? 'Aplicar' : 'Agregar'}</button></div>
  </form>`;
  hmDraftIcons(); hmDraftSwatches(); hmDraftPreview();
  dlg.showModal();
  setTimeout(() => $id(i >= 0 ? 'hd-name' : 'hd-mod').focus(), 30);
}
function hmDraftPreview() { $id('hd-prev').innerHTML = hmTileHtml(HM.draft); }
function hmDraftIcons() {
  const q = ($id('hd-icq').value || '').toLowerCase().trim(), cur = HM.draft.icon;
  let list = [...new Set([cur, ...(typeof FA_ICONS_FLAT !== 'undefined' ? FA_ICONS_FLAT : []), ...HM.meta.modules.map(m => m.icon)])];
  if (q) list = list.filter(ic => ic.replace('fa-', '').includes(q));
  // Un nombre exacto de Font Awesome que no está en la lista también vale
  if (q && /^[a-z0-9-]+$/.test(q) && !list.includes('fa-' + q)) list.push('fa-' + q);
  $id('hd-icons').innerHTML = list.map(ic => `<button type="button" data-ic="${esc(ic)}" class="${ic === cur ? 'on' : ''}" title="${esc(ic.replace('fa-', ''))}" aria-label="${esc(ic.replace('fa-', ''))}"><i class="fa-solid ${esc(ic)}"></i></button>`).join('');
}
function hmDraftSwatches() {
  const cur = HM.draft.color;
  $id('hd-sw').innerHTML = HM_COLORS.map(c => `<button type="button" data-sw="${c}" class="${c === cur ? 'on' : ''}" style="background:${c}" aria-label="Color ${c}"></button>`).join('')
    + `<input type="color" id="hd-color" value="${esc(cur)}" title="Otro color" aria-label="Otro color">`;
}
function hmDlgSetup() {
  const dlg = $id('hm-dlg');
  dlg.addEventListener('click', e => {
    if (e.target === dlg || e.target.closest('[data-x]')) { dlg.close(); return; }
    const ic = e.target.closest('[data-ic]'); if (ic) { HM.draft.icon = ic.dataset.ic; hmDraftIcons(); hmDraftPreview(); return; }
    const sw = e.target.closest('[data-sw]'); if (sw) { HM.draft.color = sw.dataset.sw; hmDraftSwatches(); hmDraftPreview(); }
  });
  dlg.addEventListener('input', e => {
    const t = HM.draft, el = e.target; if (!t) return;
    if (el.id === 'hd-icq') { hmDraftIcons(); return; }
    if (el.id === 'hd-color') { t.color = el.value.toLowerCase(); dlg.querySelectorAll('[data-sw]').forEach(b => b.classList.toggle('on', b.dataset.sw === t.color)); hmDraftPreview(); return; }
    const k = { 'hd-name': 'name', 'hd-desc': 'desc', 'hd-badge': 'badge', 'hd-href': 'href' }[el.id];
    if (k) { t[k] = el.value; el.closest('.hm-f')?.classList.remove('bad'); hmDraftPreview(); }
  });
  dlg.addEventListener('change', e => {
    const t = HM.draft, el = e.target; if (!t) return;
    if (el.id === 'hd-mod') {
      const m = HM.meta.modules.find(x => x.id === el.value), prev = HM.meta.modules.find(x => x.href === t.href);
      if (!m) { if (prev) { t.href = ''; $id('hd-href').value = ''; $id('hd-href').focus(); } return; }
      // Completa lo que esté vacío o lo que venía de la herramienta anterior
      t.href = m.href; $id('hd-href').value = m.href;
      if (!t.name || t.name === prev?.label) { t.name = m.label; $id('hd-name').value = m.label; }
      if (!t.desc || t.desc === prev?.desc) { t.desc = m.desc; $id('hd-desc').value = m.desc; }
      if (t.icon === 'fa-link' || t.icon === prev?.icon) { t.icon = m.icon; hmDraftIcons(); }
      dlg.querySelectorAll('.hm-f.bad').forEach(f => f.classList.remove('bad'));
      hmDraftPreview(); return;
    }
    if (el.id === 'hd-newtab') t.newtab = el.checked;
    if (el.id === 'hd-aud') { t.audience = el.value; $id('hd-teams').hidden = el.value !== 'teams'; }
    if (el.closest('#hd-teams')) { t.teams = [...$id('hd-teams').querySelectorAll('input:checked')].map(x => +x.value); $id('hd-teams-err').style.display = 'none'; }
  });
  dlg.addEventListener('submit', e => {
    e.preventDefault();
    const t = HM.draft;
    t.name = t.name.trim(); t.href = t.href.trim(); t.desc = t.desc.trim(); t.badge = t.badge.trim();
    let bad = null;
    if (!hmHrefOk(t.href)) { $id('hd-f-href').classList.add('bad'); bad = bad || 'hd-href'; }
    if (!t.name) { $id('hd-f-name').classList.add('bad'); bad = bad || 'hd-name'; }
    if (t.audience === 'teams' && !t.teams.length) { $id('hd-teams-err').style.display = 'block'; bad = bad || 'hd-aud'; }
    if (bad) { $id(bad).focus(); return; }
    if (t.audience !== 'teams') t.teams = [];
    if (HM.editIdx >= 0) HM.cfg.tiles[HM.editIdx] = t; else HM.cfg.tiles.push(t);
    dlg.close(); hmTouch();
    admToast(HM.editIdx >= 0 ? 'Bloque actualizado. Guarda para aplicarlo.' : 'Bloque agregado al final. Guarda para aplicarlo.');
  });
  dlg.addEventListener('close', () => { HM.draft = null; });
}

async function hmSave(reset = false) {
  const btn = $id('hm-save'); btn.disabled = true;
  try {
    const r = await accApi('home_save', reset ? { reset: true } : { config: HM.cfg });
    hmSet(r.config); hmTouch();
    admToast(reset ? 'El Inicio volvió a como venía' : 'Inicio guardado: ya lo ven todos');
  } catch (e) { btn.disabled = !hmDirty(); }
}

document.addEventListener('DOMContentLoaded', () => {
  const sec = $id('sec-home'); if (!sec) return;
  sec.addEventListener('click', hmOnClick);
  sec.addEventListener('change', hmOnChange);
  sec.addEventListener('input', hmOnInput);
  hmSetupDrag(sec); hmDlgSetup();
  $id('hm-save').addEventListener('click', () => hmSave());
  $id('hm-reset').addEventListener('click', () => {
    if (confirm('¿Volver el Inicio a como venía? Se pierden los bloques y secciones que armaste.')) hmSave(true);
  });
  window.addEventListener('beforeunload', e => { if (hmDirty()) { e.preventDefault(); e.returnValue = ''; } });
});
SECTION_LOADERS.home = hmLoad;
