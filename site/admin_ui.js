/* admin_ui.js — Lógica de las secciones de accesos, marcas, equipos, usuarios, ajustes, auditoría y respaldo.
   Depende de admin.php: ADM_URL y admToast(). */
'use strict';

let S = null;          // snapshot del servidor
let BV = null;         // valores de marca en los datos
const DR = { kind: null, id: 0, tab: null, draft: null }; window.DR = DR;   // g7-on (data-on-…="@set") lo busca en window

// ── Utilidades ───────────────────────────────────────────────
function esc(s) { return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
const $id = (id) => document.getElementById(id);
async function accApi(action, data = {}) {
  let d;
  try {
    const r = await fetch(ADM_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...data, action }) });
    d = await r.json();
  } catch (e) { d = { error: 'No se pudo conectar con el servidor' }; }
  if (d.error) { admToast(d.error, true); throw new Error(d.error); }
  return d;
}
async function accRefresh() { S = await accApi('acc_snapshot'); accRenderAll(); return S; }
const userById = (id) => S.users.find(u => u.id === +id);
const teamById = (id) => S.teams.find(t => t.id === +id);
const brandById = (id) => S.brands.find(b => b.id === +id);
const modById = (id) => S.modules.find(m => m.id === id);
const uName = (u) => u ? (u.display_name || u.username) : '—';
const AV_COLORS = ['#ff6600', '#a78bfa', '#4ade80', '#60a5fa', '#f59e0b', '#ec4899'];
function avatar(u, size = 28) {
  if (!u) return '';
  if (u.avatar) return `<img class="acc-av" src="${esc(u.avatar)}" style="width:${size}px;height:${size}px" alt="">`;
  const i = (uName(u)[0] || '?').toUpperCase();
  return `<span class="acc-av" style="width:${size}px;height:${size}px;background:${AV_COLORS[i.charCodeAt(0) % 6]};font-size:${Math.floor(size * .42)}px">${esc(i)}</span>`;
}
const chip = (label, color, extra = '', title = '') => `<span class="acc-chip ${extra}" ${title ? `title="${esc(title)}"` : ''}>${color ? `<span class="dot" style="background:${esc(color)}"></span>` : ''}${esc(label)}</span>`;
function ago(dt) {
  if (!dt) return '—';
  const s = (Date.now() - (window.G7Time && G7Time.parse(dt) || new Date(dt.replace(' ', 'T'))).getTime()) / 1000;
  if (s < 60) return 'recién'; if (s < 3600) return Math.floor(s / 60) + ' min'; if (s < 86400) return Math.floor(s / 3600) + ' h';
  if (s < 86400 * 30) return Math.floor(s / 86400) + ' d';
  return (window.G7Time && G7Time.parse(dt) || new Date(dt.replace(' ', 'T'))).toLocaleDateString('es-AR', window.G7Time ? { timeZone: G7Time.cfg.tz } : {});
}
/** Botonera de opciones. fn: nombre de la función; pre: argumentos que van antes del valor elegido (formato g7-on: 'j:5', 's:texto').
    Sin código en línea (CSP estricta): antes recibía un texto con código como "((v)=>accSetMode('x',v))". */
function seg(options, value, fn, cls = '', ...pre) {
  const preAttrs = pre.map((a, i) => ` data-click-${i + 1}="${esc(a)}"`).join('');
  return `<div class="acc-seg">${options.map(o => `<button type="button" class="${cls}${o.v} ${o.v === value ? 'on' : ''}" title="${esc(o.t || '')}" data-on-click="${esc(fn)}"${preAttrs} data-click-${pre.length + 1}="s:${esc(o.v)}">${o.i ? `<i class="fa-solid ${o.i}"></i>` : ''}${esc(o.l)}</button>`).join('')}</div>`;
}
/** Interruptor. fn: función (o @set) que se llama al cambiar, con los argumentos de g7-on ('checked', 'this', 's:…'). Sin código en línea (CSP). */
function toggle(id, checked, fn = '', ...args) {
  const on = fn ? ` data-on-change="${fn}"` + args.map((a, i) => ` data-change-${i + 1}="${esc(a)}"`).join('') : '';
  return `<label class="adm-toggle"><input type="checkbox" id="${id}" ${checked ? 'checked' : ''}${on}><div class="adm-toggle-track"></div><div class="adm-toggle-thumb"></div></label>`;
}
function brandsLabel(u) {
  if (u.role === 'admin') return chip('Todas · admin', null, 'inherit');
  const eff = u.brands_effective;
  if (!S.settings.brands_enabled || S.settings.brands_enabled === '0') {
    const any = u.brands_direct.length || Object.keys(u.brands_team).length;
    if (!any) return '<span class="acc-muted">—</span>';
  }
  if (eff === null && !u.brands_direct.length && !Object.keys(u.brands_team).length) return chip('Todas', null, 'inherit', 'No tiene marcas asignadas');
  const ids = [...new Set([...u.brands_direct, ...Object.keys(u.brands_team).map(Number)])];
  if (!ids.length) return chip('Ninguna', '#ef4444');
  return ids.map(id => { const b = brandById(id); if (!b) return ''; const via = u.brands_team[id]; return chip(b.name, b.color, via && !u.brands_direct.includes(id) ? 'inherit' : '', via ? 'Vía ' + (teamById(via)?.name || 'equipo') : 'Asignada directamente'); }).join('');
}
const MODE_OPTS = (cur) => Object.entries(S.modes).filter(([v]) => v !== 'superadmins' || S.me_can_super || cur === v).map(([v, m]) => ({ v, l: m.label, i: m.icon, t: m.desc }));
const EFFECT_OPTS = [{ v: 'inherit', l: 'Heredar', t: 'Usa la regla del módulo y de sus equipos' }, { v: 'allow', l: 'Permitir', t: 'Le da acceso a esta persona' }, { v: 'deny', l: 'Denegar', t: 'Le quita acceso a esta persona' }];
const actLabel = (a) => S.action_labels[a] || a;

// ── Navegación de secciones ───────────────────────────────────
const SECTION_LOADERS = {
  users: () => accRenderUsers(), teams: () => accRenderTeams(), brands: () => accRenderBrands(),
  modules: () => accRenderModules(), matrix: () => accRenderMatrix(), config: () => accRenderSettings(),
  audit: () => accAuditLoad(), backup: () => {},
  logs: () => admLoadLogs(), topbar: () => admLoadTopbar(), notif: () => admLoadNotif(), plugins: () => admLoadPlugins(),
  content: () => { admLoadContentStats(); ucLoad(); }, overview: () => { admLoadContentStats(); accRenderOverview(); },
};
function admShow(sec, btn) {
  if (!$id('sec-' + sec)) sec = 'overview';
  document.querySelectorAll('.adm-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.adm-nav-item').forEach(b => b.classList.remove('active'));
  $id('sec-' + sec).classList.add('active');
  (btn || document.querySelector(`.adm-nav-item[data-sec="${sec}"]`))?.classList.add('active');
  if (location.hash.slice(1) !== sec) history.replaceState(null, '', '#' + sec);
  if (S || !['users', 'teams', 'brands', 'modules', 'matrix', 'config', 'overview', 'content'].includes(sec)) SECTION_LOADERS[sec]?.();
}
function accCurrentSection() { return document.querySelector('.adm-section.active')?.id.replace('sec-', ''); }
function accRenderAll() {
  accFillFilters();
  const sec = accCurrentSection();
  if (sec && SECTION_LOADERS[sec] && !['logs', 'topbar', 'notif', 'plugins', 'audit', 'backup'].includes(sec)) SECTION_LOADERS[sec]();
  if (DR.kind) accDrawerRender();
}

// ── Resumen ───────────────────────────────────────────────────
function accRenderOverview() {
  const box = $id('acc-overview'); if (!box || !S) return;
  const active = S.users.filter(u => u.active);
  const noTeam = active.filter(u => u.role !== 'admin' && !u.teams.length);
  const restricted = S.modules.filter(m => !m.core && m.mode !== 'everyone');
  const ovr = S.users.reduce((n, u) => n + Object.keys(u.overrides).length, 0);
  const brandsOn = S.settings.brands_enabled === '1';
  const noBrand = active.filter(u => u.role !== 'admin' && !u.brands_direct.length && !Object.keys(u.brands_team).length);
  box.innerHTML = `
    <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-user-lock"></i> Accesos y marcas</div></div>
    <div class="adm-card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
      <div class="adm-stat" style="cursor:pointer" data-on-click="admShow" data-click-1="s:modules"><div class="adm-stat-val">${restricted.length}</div><div class="adm-stat-label">Módulos restringidos de ${S.modules.filter(m => !m.core).length}</div></div>
      <div class="adm-stat" style="cursor:pointer" data-on-click="admShow" data-click-1="s:matrix"><div class="adm-stat-val">${ovr}</div><div class="adm-stat-label">Excepciones por usuario</div></div>
      <div class="adm-stat" style="cursor:pointer" data-on-click="admShow" data-click-1="s:brands"><div class="adm-stat-val" style="color:${brandsOn ? '#22c55e' : 'var(--g7-text-4)'}">${brandsOn ? 'Activa' : 'Apagada'}</div><div class="adm-stat-label">Separación por marca</div></div>
      <div class="adm-stat" style="cursor:pointer" data-on-click="admShow" data-click-1="s:users"><div class="adm-stat-val" style="color:${noTeam.length ? '#fbbf24' : '#22c55e'}">${noTeam.length}</div><div class="adm-stat-label">Usuarios activos sin equipo</div></div>
    </div>
    ${(noTeam.length || (brandsOn && noBrand.length)) ? `<div class="adm-card-body" style="padding-top:0">
      ${noTeam.length ? `<div class="acc-warn"><i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i><div><b>Sin equipo:</b> ${noTeam.map(u => `<a href="#" data-on-click="accUserOpenNav" data-click-1="j:${u.id}" style="color:inherit">${esc(uName(u))}</a>`).join(', ')}. No entran a módulos que estén en modo "Por equipos".</div></div>` : ''}
      ${brandsOn && noBrand.length ? `<div class="acc-warn"><i class="fa-solid fa-tags" style="margin-top:2px"></i><div><b>Sin marca:</b> ${noBrand.map(u => esc(uName(u))).join(', ')}. ${S.settings.brand_no_assignment === 'none' ? 'No ven contenido de ninguna marca.' : 'Ven todas las marcas.'}</div></div>` : ''}
    </div>` : ''}</div>`;
}

// ── Filtros compartidos ───────────────────────────────────────
function accFillFilters() {
  const teamOpts = `<option value="">Todos los equipos</option><option value="none">Sin equipo</option>` + S.teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
  for (const id of ['acc-u-team', 'acc-m-team']) { const el = $id(id); if (el) { const v = el.value; el.innerHTML = teamOpts; el.value = v; } }
  const bEl = $id('acc-u-brand');
  if (bEl) { const v = bEl.value; bEl.innerHTML = `<option value="">Todas las marcas</option>` + S.brands.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('') + `<option value="none">Sin marca</option>`; bEl.value = v; }
  const aEl = $id('acc-a-user');
  if (aEl) { const v = aEl.value; aEl.innerHTML = `<option value="">Todas las personas</option>` + S.users.filter(u => u.role === 'admin').map(u => `<option value="${u.id}">${esc(uName(u))}</option>`).join(''); aEl.value = v; }
}
function userMatches(u, q, team, status) {
  if (status === 'active' && !u.active) return false;
  if (status === 'inactive' && u.active) return false;
  if (team === 'none' && u.teams.length) return false;
  if (team && team !== 'none' && !u.teams.some(t => t.team_id === +team)) return false;
  if (q) { const s = (u.display_name + ' ' + u.username + ' ' + u.email).toLowerCase(); if (!s.includes(q.toLowerCase())) return false; }
  return true;
}

// ═════════════════════════════════════════════════════════════
// USUARIOS
// ═════════════════════════════════════════════════════════════
function accRenderUsers() {
  const tb = $id('acc-users-tbody'); if (!tb || !S) return;
  const q = $id('acc-u-q').value.trim(), team = $id('acc-u-team').value, brand = $id('acc-u-brand').value, status = $id('acc-u-status').value;
  const viewMods = S.modules.filter(m => !m.core);
  const rows = S.users.filter(u => {
    if (!userMatches(u, q, team, status)) return false;
    if (brand === 'none') return u.role !== 'admin' && !u.brands_direct.length && !Object.keys(u.brands_team).length;
    if (brand) return u.role === 'admin' || u.brands_direct.includes(+brand) || u.brands_team[brand] !== undefined;
    return true;
  });
  if (!rows.length) { tb.innerHTML = '<tr><td colspan="7" class="acc-empty">Sin resultados</td></tr>'; return; }
  tb.innerHTML = rows.map(u => {
    const n = viewMods.filter(m => u.access[m.id + '.view'][0]).length;
    const nOvr = Object.keys(u.overrides).length;
    return `<tr class="acc-row-click" data-on-click="accUserOpen" data-click-1="j:${u.id}" style="${u.active ? '' : 'opacity:.5'}">
      <td><div class="acc-user">${avatar(u)}<div style="min-width:0"><b>${esc(uName(u))}</b><small>@${esc(u.username)}${u.position ? ' · ' + esc(u.position) : ''}</small></div></div></td>
      <td>${u.is_super ? '<span class="badge badge-admin"><i class="fa-solid fa-crown"></i> Superadmin</span>' : u.role === 'admin' ? '<span class="badge badge-admin">Admin</span>' : '<span class="badge badge-member">Usuario</span>'}${u.active ? '' : ' <span class="badge badge-inactive">Inactivo</span>'}</td>
      <td><div class="acc-chips">${u.teams.map(t => { const tm = teamById(t.team_id); return tm ? chip(tm.name + (t.role === 'admin' ? ' ★' : ''), tm.color, '', t.role === 'admin' ? 'Admin del equipo' : '') : ''; }).join('') || '<span class="acc-muted">Sin equipo</span>'}</div></td>
      <td><div class="acc-chips">${brandsLabel(u)}</div></td>
      <td>${u.role === 'admin' ? '<span class="acc-pill admin">Todo</span>' : `<span style="font-size:11px;font-weight:700">${n}/${viewMods.length}</span>${nOvr ? ` <span class="acc-chip" title="Excepciones personales">${nOvr} exc.</span>` : ''}<div class="acc-bar"><i style="width:${Math.round(n / viewMods.length * 100)}%"></i></div>`}</td>
      <td class="acc-muted">${ago(u.last_seen || u.last_login)}</td>
      <td><button class="btn-icon" title="Editar"><i class="fa-solid fa-chevron-right"></i></button></td></tr>`;
  }).join('');
}

function accUserOpen(id) {
  const u = id ? userById(id) : null;
  DR.kind = 'user'; DR.id = id; DR.tab = 'profile';
  DR.draft = u ? {
    username: u.username, display_name: u.display_name || '', email: u.email || '', role: u.role === 'admin' ? 'admin' : 'user', active: !!u.active, password: '',
    team_ids: u.teams.map(t => t.team_id), primary_team: (u.teams.find(t => t.is_primary) || u.teams[0] || {}).team_id || 0,
    team_admin_ids: u.teams.filter(t => t.role === 'admin').map(t => t.team_id), brand_ids: [...u.brands_direct],
  } : { username: '', display_name: '', email: '', role: 'user', active: true, password: '', team_ids: S.settings.new_user_team ? [+S.settings.new_user_team] : [], primary_team: +S.settings.new_user_team || 0, team_admin_ids: [], brand_ids: [] };
  accDrawerOpen();
}
function accUserTabs() {
  return [['profile', 'Perfil', 'fa-id-card'], ['teams', 'Equipos y marcas', 'fa-people-group'], ['access', 'Accesos', 'fa-key', !DR.id]];
}
function accUserBody() {
  const d = DR.draft, u = DR.id ? userById(DR.id) : null;
  if (DR.tab === 'profile') {
    return `
      <div class="adm-form-grid">
        <div><label class="adm-label">Usuario${DR.id ? '' : ' *'}</label><input class="adm-input" ${DR.id ? 'disabled' : ''} value="${esc(d.username)}" data-on-input="@set" data-input-1="s:DR.draft.username" data-input-2="value" placeholder="nombre.apellido"></div>
        <div><label class="adm-label">Nombre para mostrar</label><input class="adm-input" value="${esc(d.display_name)}" data-on-input="@set" data-input-1="s:DR.draft.display_name" data-input-2="value"></div>
        <div style="grid-column:1/-1"><label class="adm-label">Email</label><input class="adm-input" type="email" value="${esc(d.email)}" data-on-input="@set" data-input-1="s:DR.draft.email" data-input-2="value" placeholder="correo@grupolatino.lat"></div>
      </div>
      <div class="acc-field-row"><div><div class="lbl">Rol</div><div class="acc-help">Un admin global entra a todo, ve todas las marcas y maneja este panel.</div></div>
        <div class="ctl narrow">${seg([{ v: 'user', l: 'Usuario', i: 'fa-user' }, { v: 'admin', l: 'Admin global', i: 'fa-user-shield' }], d.role, 'accUserSetRole')}</div></div>
      ${S.me_super && DR.id && d.role === 'admin' ? `<div class="acc-field-row"><div><div class="lbl">Superadmin</div><div class="acc-help">Nombra y quita admins, y maneja claves de IA, auditoría y respaldo. Nadie más puede modificar esta cuenta.</div></div>
        <div class="ctl narrow">${toggle('acc-ud-super', !!(S.users.find(x => x.id == DR.id) || {}).is_super, 'accUserSuper', 'checked')}</div></div>` : ''}
      <div class="acc-field-row"><div><div class="lbl">Cuenta activa</div><div class="acc-help">Una cuenta inactiva no puede entrar y se cierra su sesión.</div></div>
        <div class="ctl narrow">${toggle('acc-ud-active', d.active, '@set', 's:DR.draft.active', 'checked')}</div></div>
      <div class="acc-field-row" style="align-items:flex-start"><div><div class="lbl">Contraseña${DR.id ? '' : ' *'}</div><div class="acc-help">${DR.id ? 'Dejala vacía para no cambiarla.' : ''} Mínimo ${esc(S.settings.password_min_length)} caracteres.</div></div>
        <div class="ctl"><div style="display:flex;gap:6px"><input class="adm-input" id="acc-ud-pw" type="password" value="${esc(d.password)}" data-on-input="@set" data-input-1="s:DR.draft.password" data-input-2="value" autocomplete="new-password">
          <button class="btn-icon" type="button" title="Mostrar" data-on-click="accTogglePw"><i class="fa-solid fa-eye"></i></button>
          <button class="btn btn-ghost btn-sm" type="button" data-on-click="accGenToggle" aria-expanded="false" id="acc-gen-btn"><i class="fa-solid fa-wand-magic-sparkles"></i> Generar</button></div>
          <div class="acc-gen" id="acc-gen" hidden></div></div></div>
      ${u ? `<div class="acc-field-row"><div><div class="lbl">Datos de acceso</div><div class="acc-help">Mensaje listo para mandarle por WhatsApp o email: dirección, usuario y, si la acabas de poner, la contraseña.</div></div>
        <div class="ctl narrow"><button class="btn btn-ghost btn-sm" type="button" data-on-click="accCredsShow" data-click-1="j:${u.id}"><i class="fa-regular fa-copy"></i> Copiar datos</button></div></div>` : ''}
      ${u ? `<div class="acc-sub-h">Actividad</div><div class="acc-help">Creado: ${esc(u.created_at || '—')} · Último login: ${esc(u.last_login || '—')} · Visto: ${esc(u.last_seen || '—')}</div>
      ${u.id !== S.me ? `<div class="acc-danger"><div style="font-size:12px;font-weight:700;color:#ef4444;margin-bottom:4px">Eliminar usuario</div>
        <div class="acc-help" style="margin-bottom:10px">Borra la cuenta y sus permisos. Su contenido queda. Si solo quieres cortarle el acceso, desactivala.</div>
        <button class="btn btn-ghost btn-sm" data-on-click="ucOpenUser" data-click-1="j:${u.id}"><i class="fa-solid fa-right-left"></i> Pasar su contenido a otro</button>
        <button class="btn btn-danger btn-sm" data-on-click="accUserDelete" data-click-1="j:${u.id}"><i class="fa-solid fa-trash"></i> Eliminar a ${esc(uName(u))}</button></div>` : ''}` : ''}`;
  }
  if (DR.tab === 'teams') {
    const brandsOn = S.settings.brands_enabled === '1';
    const teamBrands = {};
    d.team_ids.forEach(tid => (teamById(tid)?.brands || []).forEach(b => teamBrands[b] = tid));
    return `
      <div class="acc-sub-h">Equipos</div>
      ${S.teams.length ? S.teams.map(t => {
        const on = d.team_ids.includes(t.id);
        return `<div class="acc-list-row">
          <label class="acc-check" style="flex:1"><input type="checkbox" ${on ? 'checked' : ''} data-on-change="accUserTeamToggle" data-change-1="j:${t.id}" data-change-2="checked">${chip(t.name, t.color)}<span class="acc-muted">${t.members.length} miembros${t.active ? '' : ' · inactivo'}</span></label>
          ${on ? `<label class="acc-check acc-muted" title="Equipo principal: define su topbar y su equipo por defecto"><input type="radio" name="acc-primary" ${d.primary_team === t.id ? 'checked' : ''} data-on-change="@set" data-change-1="s:DR.draft.primary_team" data-change-2="j:${t.id}"> Principal</label>
          <select class="adm-select" style="width:auto" data-on-change="accUserTeamRole" data-change-1="j:${t.id}" data-change-2="value"><option value="member">Miembro</option><option value="admin" ${d.team_admin_ids.includes(t.id) ? 'selected' : ''}>Admin del equipo</option></select>` : ''}
        </div>`;
      }).join('') : '<div class="acc-muted">Todavía no hay equipos.</div>'}
      <div class="acc-sub-h">Marcas</div>
      ${!brandsOn ? `<div class="acc-info"><i class="fa-solid fa-circle-info" style="margin-top:2px"></i><div>La separación por marca está <b>apagada</b>: por ahora todos ven todo. Esto se aplica cuando la actives en <a href="#" data-on-click="accGoBrands" style="color:var(--g7-accent)">Marcas</a>.</div></div>` : ''}
      ${d.role === 'admin' ? `<div class="acc-info"><i class="fa-solid fa-user-shield" style="margin-top:2px"></i><div>Como admin global ve todas las marcas. Puede elegir una con el selector de la topbar.</div></div>` : ''}
      ${S.brands.map(b => {
        const via = teamBrands[b.id];
        return `<div class="acc-list-row"><label class="acc-check" style="flex:1"><input type="checkbox" ${d.brand_ids.includes(b.id) ? 'checked' : ''} data-on-change="accUserBrandToggle" data-change-1="j:${b.id}" data-change-2="checked">${chip(b.name, b.color)}${b.active ? '' : '<span class="acc-muted">inactiva</span>'}</label>
          ${via ? `<span class="acc-chip inherit">También vía ${esc(teamById(via)?.name || 'equipo')}</span>` : ''}</div>`;
      }).join('')}
      <div class="acc-help" style="margin-top:10px">Ve la unión de sus marcas y las de sus equipos.${S.settings.brand_no_assignment === 'none' ? ' Sin ninguna marca no ve contenido de marcas.' : ' Sin ninguna marca ve todas.'}</div>`;
  }
  // Accesos
  return accAccessEditor(u);
}
function accAccessEditor(u) {
  if (u.role === 'admin') return `<div class="acc-info"><i class="fa-solid fa-user-shield" style="margin-top:2px"></i><div>Es admin global: entra a todos los módulos y puede hacer todo. Las excepciones no se aplican a admins.</div></div>`;
  const groups = {};
  S.modules.forEach(m => (groups[m.group] = groups[m.group] || []).push(m));
  const nOvr = Object.keys(u.overrides).length;
  return `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px">
      <div class="acc-help">Estado real de cada módulo para esta persona y por qué. Usa <b>Permitir</b> o <b>Denegar</b> para hacer una excepción solo para ella.</div>
      ${nOvr ? `<button class="btn btn-ghost btn-sm" data-on-click="accUserClearOverrides" data-click-1="j:${u.id}"><i class="fa-solid fa-eraser"></i> Quitar ${nOvr} excepción${nOvr > 1 ? 'es' : ''}</button>` : ''}</div>
    ${Object.entries(groups).map(([g, mods]) => `<div class="acc-sub-h">${esc(g)}</div>` + mods.map(m => m.actions.map((a, i) => {
      const [ok, , why] = u.access[m.id + '.' + a];
      const ovr = u.overrides[m.id + '.' + a] || 'inherit';
      return `<div class="acc-list-row" style="${i ? 'padding-left:42px' : ''}">
        ${i ? `<span style="font-size:11.5px;font-weight:600;flex:1">${esc(actLabel(a))}</span>` : `<div class="acc-mod-ic" style="width:30px;height:30px"><i class="fa-solid ${esc(m.icon)}"></i></div><div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:700">${esc(m.label)}${m.actions.length > 1 ? ` · ${esc(actLabel(a))}` : ''}</div><div class="acc-muted">${esc(S.modes[m.mode]?.label || '')}</div></div>`}
        <span class="acc-pill ${ok ? 'ok' : 'no'}" title="${esc(why)}"><i class="fa-solid ${ok ? 'fa-check' : 'fa-xmark'}"></i>${esc(why)}</span>
        ${m.core ? '<span class="acc-muted" style="width:178px;text-align:right">Siempre disponible</span>' : seg(EFFECT_OPTS, ovr, 'accSetOverride', 'e-', 'j:' + u.id, 's:' + m.id, 's:' + a)}
      </div>`;
    }).join('')).join('')).join('')}`;
}
async function accUserSuper(on) {
  try { await accApi('user_super', { id: DR.id, on }); admToast(on ? 'Ahora es superadmin' : 'Ya no es superadmin'); } catch (e) {}
  await accRefresh(); accDrawerRender();
}
function accUserSetRole(v) { DR.draft.role = v; accDrawerRender(); }
function accUserTeamToggle(tid, on) {
  const d = DR.draft;
  d.team_ids = on ? [...new Set([...d.team_ids, tid])] : d.team_ids.filter(x => x !== tid);
  if (!d.team_ids.includes(d.primary_team)) d.primary_team = d.team_ids[0] || 0;
  accDrawerRender();
}
function accUserTeamRole(tid, role) { const d = DR.draft; d.team_admin_ids = role === 'admin' ? [...new Set([...d.team_admin_ids, tid])] : d.team_admin_ids.filter(x => x !== tid); }
function accUserBrandToggle(bid, on) { const d = DR.draft; d.brand_ids = on ? [...new Set([...d.brand_ids, bid])] : d.brand_ids.filter(x => x !== bid); }
// ── Generador de contraseñas ───────────────────────────────
const GEN = { len: 14, num: true, sym: true, easy: true, pw: '' };
function accGenMake() {
  const lo = GEN.easy ? 'abcdefghjkmnpqrstuvwxyz' : 'abcdefghijklmnopqrstuvwxyz', up = GEN.easy ? 'ABCDEFGHJKLMNPQRSTUVWXYZ' : 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  const nu = GEN.easy ? '23456789' : '0123456789', sy = '!#$%&*+-=?@';
  const sets = [lo, up, ...(GEN.num ? [nu] : []), ...(GEN.sym ? [sy] : [])], all = sets.join('');
  const rnd = n => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; };
  const out = sets.map(s => s[rnd(s.length)]);                       // al menos uno de cada tipo elegido
  while (out.length < GEN.len) out.push(all[rnd(all.length)]);
  for (let i = out.length - 1; i > 0; i--) { const j = rnd(i + 1); [out[i], out[j]] = [out[j], out[i]]; }
  return out.join('');
}
function accGenStrength(p) { let s = 0; if (p.length >= 12) s++; if (p.length >= 16) s++; if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^A-Za-z0-9]/.test(p)) s++; return s; }
function accGenDraw() {
  const box = $id('acc-gen'); if (!box) return;
  const s = accGenStrength(GEN.pw), lv = ['Débil', 'Débil', 'Aceptable', 'Buena', 'Muy buena', 'Excelente'][s], col = ['#ef4444', '#ef4444', '#f59e0b', '#84cc16', '#22c55e', '#22c55e'][s];
  box.innerHTML = `<div class="acc-gen-pw"><code id="acc-gen-out">${esc(GEN.pw)}</code><button class="btn-icon" type="button" title="Otra" aria-label="Generar otra" data-on-click="accGenNew"><i class="fa-solid fa-rotate"></i></button></div>
    <div class="acc-gen-meter"><i style="width:${s * 20}%;background:${col}"></i></div><div class="acc-help" style="margin:4px 0 10px">${lv}</div>
    <label class="acc-gen-len">Largo <input type="range" min="8" max="32" value="${GEN.len}" data-on-input="accGenSet" data-input-1="s:len" data-input-2="nvalue"><b>${GEN.len}</b></label>
    <div class="acc-gen-opts"><label><input type="checkbox" ${GEN.num ? 'checked' : ''} data-on-change="accGenSet" data-change-1="s:num" data-change-2="checked"> Números</label>
      <label><input type="checkbox" ${GEN.sym ? 'checked' : ''} data-on-change="accGenSet" data-change-1="s:sym" data-change-2="checked"> Símbolos</label>
      <label title="Sin l, I, 1, O ni 0: más fácil de dictar"><input type="checkbox" ${GEN.easy ? 'checked' : ''} data-on-change="accGenSet" data-change-1="s:easy" data-change-2="checked"> Fácil de leer</label></div>
    <div style="display:flex;gap:6px;margin-top:10px"><button class="btn btn-primary btn-sm" type="button" data-on-click="accGenUse"><i class="fa-solid fa-check"></i> Usar esta</button><button class="btn btn-ghost btn-sm" type="button" data-on-click="accGenCopy"><i class="fa-regular fa-copy"></i> Copiar</button></div>`;
}
function accGenToggle() {
  const box = $id('acc-gen'), b = $id('acc-gen-btn'); if (!box) return;
  box.hidden = !box.hidden; b?.setAttribute('aria-expanded', String(!box.hidden));
  if (!box.hidden) { GEN.pw = accGenMake(); accGenDraw(); }
}
function accGenUse() {
  DR.draft.password = GEN.pw;
  const i = $id('acc-ud-pw'); if (i) { i.value = GEN.pw; i.type = 'text'; }
  $id('acc-gen').hidden = true; $id('acc-gen-btn')?.setAttribute('aria-expanded', 'false');
  admToast('Contraseña puesta: guarda para aplicarla');
}
function accGenCopy() { accCopy(GEN.pw, 'Contraseña copiada'); }
function accGenPassword() { GEN.pw = accGenMake(); accGenUse(); }
function accCopy(text, msg) {
  const done = () => admToast(msg || 'Copiado');
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, () => accCopyOld(text, done)); else accCopyOld(text, done);
}
function accCopyOld(text, done) { const t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.append(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} t.remove(); }

// ── Datos de acceso para copiar ────────────────────────────
function accCredsText(u, pw) {
  const first = String(u.display_name || u.username).trim().split(/\s+/)[0], plat = S.settings.platform_name || document.title.split(/[—·-]/).pop().trim();
  return `Hola ${first}:\n\nYa tienes acceso a ${plat}.\n\nEntra en: ${location.origin}/toolz/login.php\nUsuario: ${u.username}\n`
    + (pw ? `Contraseña: ${pw}\n\nCuando entres, cambiala en Mi perfil.` : `Contraseña: la que te pasamos por separado.\n\nSi no la tienes, pídela y te la cambiamos.`);
}
function accCredsShow(id, pw) {
  const u = userById(id); if (!u) return;
  pw = pw ?? (DR.id === id && DR.draft.password ? DR.draft.password : '');
  const txt = accCredsText(u, pw);
  let m = $id('acc-creds');
  if (!m) { m = document.createElement('div'); m.id = 'acc-creds'; m.className = 'acc-creds'; document.body.append(m); }
  m.innerHTML = `<div class="acc-creds-box" role="dialog" aria-modal="true" aria-labelledby="acc-creds-h">
    <div class="acc-creds-h" id="acc-creds-h"><i class="fa-solid fa-paper-plane"></i> Datos de acceso de ${esc(uName(u))}</div>
    <p class="acc-help">${pw ? 'Incluye la contraseña nueva: cópialo ahora, después no se vuelve a mostrar.' : 'Sin contraseña: para incluirla, genera una nueva y guarda.'}</p>
    <textarea class="adm-input" id="acc-creds-txt" rows="9" readonly>${esc(txt)}</textarea>
    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap"><button class="btn btn-primary" type="button" data-on-click="accCopyCreds"><i class="fa-regular fa-copy"></i> Copiar mensaje</button>
      ${u.email ? `<a class="btn btn-ghost" href="mailto:${esc(u.email)}?subject=${encodeURIComponent('Tu acceso a ' + (S.settings.platform_name || ''))}&body=${encodeURIComponent(txt)}"><i class="fa-regular fa-envelope"></i> Abrir en el correo</a>` : ''}
      <button class="btn btn-ghost" type="button" style="margin-left:auto" data-on-click="accCredsClose">Cerrar</button></div></div>`;
  m.classList.add('open'); m.onclick = e => { if (e.target === m) m.classList.remove('open'); };
  setTimeout(() => $id('acc-creds-txt')?.select(), 50);
}
async function accUserSave() {
  const d = DR.draft, isNew = !DR.id, pw = d.password;
  const r = await accApi('user_save', { id: DR.id || 0, ...d });
  admToast(isNew ? 'Usuario creado ✓' : 'Usuario actualizado ✓');
  DR.id = r.id; DR.draft.password = '';
  await accRefresh();
  accUserOpen(r.id); DR.tab = DR.tab || 'profile'; accDrawerRender();
  if (pw) accCredsShow(r.id, pw);   // usuario nuevo o contraseña cambiada: los datos para mandarle, listos para copiar
}
async function accUserDelete(id) {
  ucDeleteUser(id);   // admin_contenido.js: muestra qué tiene a su nombre y quién lo hereda
}
async function accSetOverride(uid, mod, act, effect) {
  await accApi('user_override', { user_id: uid, module: mod, action_name: act, effect });
  admToast(effect === 'inherit' ? 'Excepción quitada' : effect === 'allow' ? 'Acceso permitido ✓' : 'Acceso denegado ✓');
  accRefresh();
}
async function accUserClearOverrides(uid) {
  if (!confirm('¿Quitar todas las excepciones de esta persona? Vuelve a regirse por su equipo.')) return;
  await accApi('user_overrides_clear', { user_id: uid }); admToast('Excepciones quitadas'); accRefresh();
}

// ═════════════════════════════════════════════════════════════
// EQUIPOS
// ═════════════════════════════════════════════════════════════
function accRenderTeams() {
  const g = $id('acc-teams-grid'); if (!g || !S) return;
  const noTeam = S.users.filter(u => u.active && u.role !== 'admin' && !u.teams.length);
  $id('acc-teams-warn').innerHTML = noTeam.length ? `<div class="acc-warn"><i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i><div><b>${noTeam.length} persona${noTeam.length > 1 ? 's' : ''} sin equipo:</b> ${noTeam.map(u => `<a href="#" style="color:inherit" data-on-click="accUserOpenNav" data-click-1="j:${u.id}">${esc(uName(u))}</a>`).join(', ')}.</div></div>` : '';
  if (!S.teams.length) { g.innerHTML = '<div class="acc-empty">No hay equipos todavía.</div>'; return; }
  g.innerHTML = S.teams.map(t => {
    const mem = t.members.map(m => userById(m.user_id)).filter(Boolean);
    const nMods = new Set(t.grants.filter(x => x.endsWith('.view')).map(x => x.split('.')[0])).size;
    const par = t.parent_id ? teamById(t.parent_id) : null, kids = S.teams.filter(x => x.parent_id === t.id).length;
    return `<div class="acc-card${t.depth ? ' acc-card-sub' : ''}" data-on-click="accTeamOpen" data-click-1="j:${t.id}" style="${t.active ? '' : 'opacity:.55'}${t.depth ? `;--lvl:${Math.min(t.depth, 3)}` : ''}"><div class="acc-card-stripe" style="background:${esc(t.color)}"></div>
      ${par ? `<div class="acc-muted" style="font-size:10.5px;margin-bottom:2px"><i class="fa-solid fa-turn-up fa-rotate-90" style="margin-right:4px"></i>Subequipo de ${esc(par.path || par.name)}</div>` : ''}
      <h4>${esc(t.name)}${t.active ? '' : ' <span class="badge badge-inactive">Inactivo</span>'}${kids ? ` <span class="badge" style="background:var(--g7-bg-5);color:var(--g7-text-3)">${kids} subequipo${kids > 1 ? 's' : ''}</span>` : ''}</h4><p>${esc(t.description || '')}</p>
      <div class="acc-avs">${mem.slice(0, 8).map(u => `<span title="${esc(uName(u))}">${avatar(u, 26)}</span>`).join('')}${mem.length > 8 ? `<span class="acc-av" style="width:26px;height:26px;background:var(--g7-bg-5);color:var(--g7-text-3)">+${mem.length - 8}</span>` : ''}</div>
      <div class="acc-chips">${t.brands.map(b => brandById(b)).filter(Boolean).map(b => chip(b.name, b.color)).join('') || '<span class="acc-muted">Sin marcas</span>'}</div>
      <div class="acc-kv"><span><b>${mem.length}</b>miembros</span><span><b>${nMods}</b>módulos con permiso</span></div></div>`;
  }).join('');
}
function accTeamOpen(id, parent = 0) {
  const t = id ? teamById(id) : null, p = !t && parent ? teamById(parent) : null;
  DR.kind = 'team'; DR.id = id; DR.tab = 'info';
  DR.draft = t ? { name: t.name, color: t.color, description: t.description || '', inactive: !t.active, brand_ids: [...t.brands], parent_id: t.parent_id || 0 }
    : { name: '', color: p ? p.color : '#ff6600', description: '', inactive: false, brand_ids: p ? [...p.brands] : [], copy_from: p ? String(p.id) : '', parent_id: p ? p.id : 0 };   // un subequipo arranca con los permisos y marcas de su equipo
  accDrawerOpen();
}
/** Subequipo nuevo dentro del equipo abierto. */
function accTeamNewSub(parent) { accTeamOpen(0, parent); }
/** Equipos donde puede ir este (ni él ni uno de los suyos), con su camino. */
function accTeamParentOpts(id, sel) {
  const below = new Set(id ? [id] : []);
  let grew = true; while (grew) { grew = false; S.teams.forEach(x => { if (x.parent_id && below.has(x.parent_id) && !below.has(x.id)) { below.add(x.id); grew = true; } }); }
  const height = id ? Math.max(...[...below].map(b => { let h = 1, c = teamById(b); while (c && c.id !== id && c.parent_id) { h++; c = teamById(c.parent_id); } return h; })) : 1;
  return `<option value="0">Ninguno (equipo de primer nivel)</option>` + S.teams.filter(x => !below.has(x.id) && (x.depth || 0) + 1 + height <= (S.team_max_depth || 4))
    .map(x => `<option value="${x.id}"${+sel === x.id ? ' selected' : ''}>${esc(x.path || x.name)}</option>`).join('');
}
function accTeamTabs() { return [['info', 'Datos', 'fa-pen'], ['members', 'Miembros', 'fa-users', !DR.id], ['perms', 'Permisos', 'fa-key', !DR.id]]; }
function accTeamBody() {
  const d = DR.draft, t = DR.id ? teamById(DR.id) : null;
  if (DR.tab === 'info') {
    return `<div class="adm-form-grid">
        <div><label class="adm-label">Nombre *</label><input class="adm-input" value="${esc(d.name)}" data-on-input="@set" data-input-1="s:DR.draft.name" data-input-2="value"></div>
        <div><label class="adm-label">Color</label><div style="display:flex;gap:6px"><input type="color" value="${esc(d.color)}" data-on-input="accColorInput" data-input-1="this" style="width:38px;height:33px;border:none;background:none;cursor:pointer"><input class="adm-input" value="${esc(d.color)}" data-on-input="@set" data-input-1="s:DR.draft.color" data-input-2="value"></div></div>
        <div style="grid-column:1/-1"><label class="adm-label">Descripción</label><textarea class="adm-input" data-on-input="@set" data-input-1="s:DR.draft.description" data-input-2="value">${esc(d.description)}</textarea></div>
        <div style="grid-column:1/-1"><label class="adm-label">Dentro de</label><select class="adm-select" data-on-change="@set" data-change-1="s:DR.draft.parent_id" data-change-2="value">${accTeamParentOpts(DR.id, d.parent_id)}</select>
          <div class="acc-help" style="margin-top:4px">Un subequipo es un equipo completo (sus miembros, su admin, sus permisos). En Trabajo es un subdepartamento: tiene su propia configuración y sus formularios. El admin del equipo de arriba también lo administra.</div></div>
      </div>
      ${t ? `<div class="acc-sub-h">Subequipos</div>${S.teams.filter(x => x.parent_id === t.id).map(x => `<div class="acc-list-row" style="cursor:pointer" data-on-click="accTeamOpen" data-click-1="j:${x.id}">${chip(x.name, x.color)}<span class="acc-muted" style="flex:1">${x.members.length} miembros</span><i class="fa-solid fa-chevron-right acc-muted"></i></div>`).join('') || '<div class="acc-muted" style="margin-bottom:6px">Sin subequipos</div>'}
        ${(t.depth || 0) + 1 < (S.team_max_depth || 4) ? `<button class="btn btn-sm" style="margin-top:6px" data-on-click="accTeamNewSub" data-click-1="j:${t.id}"><i class="fa-solid fa-plus"></i> Nuevo subequipo</button>` : ''}` : ''}
      ${t ? `<div class="acc-field-row"><div><div class="lbl">Equipo activo</div><div class="acc-help">Un equipo inactivo deja de dar permisos y marcas a sus miembros.</div></div><div class="ctl narrow">${toggle('acc-td-active', !d.inactive, 'accSetInactive', 'this')}</div></div>` : `
      <div class="acc-field"><label class="adm-label">Copiar permisos de</label><select class="adm-select" data-on-change="@set" data-change-1="s:DR.draft.copy_from" data-change-2="value"><option value="">Empezar sin permisos</option>${S.teams.map(x => `<option value="${x.id}"${String(x.id) === String(d.copy_from) ? ' selected' : ''}>${esc(x.path || x.name)}</option>`).join('')}</select></div>`}
      <div class="acc-sub-h">Marcas del equipo</div>
      <div class="acc-help" style="margin-bottom:6px">Todos los miembros ven estas marcas.</div>
      ${S.brands.map(b => `<div class="acc-list-row"><label class="acc-check"><input type="checkbox" ${d.brand_ids.includes(b.id) ? 'checked' : ''} data-on-change="accBrandToggle" data-change-1="this" data-change-2="j:${b.id}">${chip(b.name, b.color)}</label></div>`).join('')}
      ${t ? `<div class="acc-danger"><div style="font-size:12px;font-weight:700;color:#ef4444;margin-bottom:4px">Eliminar equipo</div><div class="acc-help" style="margin-bottom:10px">Los miembros pierden los permisos y marcas que les daba este equipo.</div>
        <button class="btn btn-danger btn-sm" data-on-click="accTeamDelete" data-click-1="j:${t.id}"><i class="fa-solid fa-trash"></i> Eliminar ${esc(t.name)}</button></div>` : ''}`;
  }
  if (DR.tab === 'members') {
    const memIds = t.members.map(m => m.user_id);
    const others = S.users.filter(u => !memIds.includes(u.id) && u.active);
    return `<div style="display:flex;gap:6px;margin-bottom:14px"><select class="adm-select" id="acc-td-add"><option value="">Agregar persona…</option>${others.map(u => `<option value="${u.id}">${esc(uName(u))} (@${esc(u.username)})</option>`).join('')}</select>
        <button class="btn btn-primary" data-on-click="accTeamAdd" data-click-1="j:${t.id}"><i class="fa-solid fa-plus"></i></button></div>
      ${t.members.length ? t.members.map(m => { const u = userById(m.user_id); if (!u) return ''; return `<div class="acc-list-row">${avatar(u)}<div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:700">${esc(uName(u))}${u.active ? '' : ' <span class="badge badge-inactive">Inactivo</span>'}</div><div class="acc-muted">@${esc(u.username)}${m.is_primary ? ' · equipo principal' : ''}</div></div>
        <select class="adm-select" style="width:auto" data-on-change="accTeamMember" data-change-1="j:${t.id}" data-change-2="j:${u.id}" data-change-3="true" data-change-4="value"><option value="member">Miembro</option><option value="admin" ${m.role === 'admin' ? 'selected' : ''}>Admin del equipo</option></select>
        <button class="btn-icon" title="Quitar del equipo" data-on-click="accTeamMember" data-click-1="j:${t.id}" data-click-2="j:${u.id}" data-click-3="false"><i class="fa-solid fa-xmark"></i></button></div>`; }).join('') : '<div class="acc-empty">Sin miembros</div>'}
      <div class="acc-help" style="margin-top:12px">Un admin del equipo puede gestionar sus miembros desde la página de Equipos, sin ser admin global.</div>`;
  }
  // Permisos del equipo
  const groups = {};
  S.modules.filter(m => !m.core).forEach(m => (groups[m.group] = groups[m.group] || []).push(m));
  return `<div class="acc-help" style="margin-bottom:10px">Qué puede hacer este equipo en cada módulo. Cuenta en los módulos en modo <b>Por equipos</b>; en modo <b>Todos</b> ya entran todos.</div>
    ${Object.entries(groups).map(([g, mods]) => `<div class="acc-sub-h">${esc(g)}</div>` + mods.map(m => `<div class="acc-list-row">
      <div class="acc-mod-ic" style="width:30px;height:30px"><i class="fa-solid ${esc(m.icon)}"></i></div>
      <div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:700">${esc(m.label)}</div><div class="acc-muted">${esc(S.modes[m.mode].label)}</div></div>
      <div style="display:flex;gap:12px">${m.actions.map(a => `<label class="acc-check"><input type="checkbox" ${t.grants.includes(m.id + '.' + a) ? 'checked' : ''} data-on-change="accTeamGrant" data-change-1="j:${t.id}" data-change-2="s:${m.id}" data-change-3="s:${a}" data-change-4="checked">${esc(actLabel(a))}</label>`).join('')}</div></div>`).join('')).join('')}`;
}
async function accTeamSave() {
  const d = DR.draft;
  const payload = { id: DR.id || 0, name: d.name, color: d.color, description: d.description, inactive: d.inactive, brand_ids: d.brand_ids, parent_id: +d.parent_id || 0 };
  if (!DR.id && d.copy_from) payload.copy_from_grants = teamById(d.copy_from)?.grants || [];
  const r = await accApi('team_save', payload);
  admToast(DR.id ? 'Equipo actualizado ✓' : 'Equipo creado ✓');
  const wasNew = !DR.id;
  await accRefresh();
  accTeamOpen(r.id); if (wasNew) { DR.tab = 'members'; accDrawerRender(); }
}
async function accTeamDelete(id) {
  if (!confirm(`¿Eliminar el equipo "${teamById(id).name}"?`)) return;
  await accApi('team_delete', { id }); admToast('Equipo eliminado'); accDrawerClose(); accRefresh();
}
async function accTeamMember(tid, uid, member, role = 'member') {
  if (!uid) return;
  await accApi('team_member', { team_id: tid, user_id: uid, member, role });
  admToast(member ? 'Miembro actualizado ✓' : 'Quitado del equipo'); accRefresh();
}
async function accTeamGrant(tid, mod, act, granted) {
  await accApi('team_grant', { team_id: tid, module: mod, action_name: act, granted });
  accRefresh();
}

// ═════════════════════════════════════════════════════════════
// MARCAS
// ═════════════════════════════════════════════════════════════
function accRenderBrands() {
  if (!S || !$id('acc-brands-grid')) return;
  const grp = S.settings_schema.find(g => g.id === 'brands');
  if (!$id('acc-brand-settings-card').classList.contains('dirty')) $id('acc-brand-settings').innerHTML = grp.fields.map(accFieldHtml).join('');
  const bs = S.brands;
  $id('acc-brands-grid').innerHTML = bs.map((b, i) => {
    const users = S.users.filter(u => u.active && u.role !== 'admin' && (u.brands_direct.includes(b.id) || u.brands_team[b.id] !== undefined));
    const rows = BV ? BV.filter(v => v.brands.includes(b.id)).reduce((n, v) => n + v.count, 0) : null;
    return `<div class="acc-card" data-on-click="accBrandOpen" data-click-1="j:${b.id}" style="${b.active ? '' : 'opacity:.55'}"><div class="acc-card-stripe" style="background:${esc(b.color)}"></div>
      <h4>${b.logo_url ? `<img src="${esc(b.logo_url)}" style="height:18px;max-width:60px;object-fit:contain" alt="">` : ''}${esc(b.name)}${b.active ? '' : ' <span class="badge badge-inactive">Inactiva</span>'}
        <span style="margin-left:auto;display:flex;gap:2px" data-on-click="@stop">
          <button class="btn-icon" title="Subir" ${i ? '' : 'disabled'} data-on-click="accBrandMove" data-click-1="j:${b.id}" data-click-2="n:-1"><i class="fa-solid fa-arrow-up"></i></button>
          <button class="btn-icon" title="Bajar" ${i < bs.length - 1 ? '' : 'disabled'} data-on-click="accBrandMove" data-click-1="j:${b.id}" data-click-2="n:1"><i class="fa-solid fa-arrow-down"></i></button></span></h4>
      <div class="acc-chips" style="margin:8px 0">${b.keywords.map(k => `<span class="acc-kw">${esc(k)}</span>`).join('')}</div>
      <div class="acc-kv"><span><b>${users.length}</b>personas</span><span><b>${b.teams.length}</b>equipos</span><span><b>${rows === null ? '…' : rows.toLocaleString('es-AR')}</b>registros</span></div></div>`;
  }).join('') || '<div class="acc-empty">No hay marcas.</div>';
  accRenderBrandAccess();
  if (!BV) accApi('brand_values').then(d => { BV = d.values; accRenderBrandValues(); accRenderBrands(); }).catch(() => {});
  else accRenderBrandValues();
}
function accRenderBrandAccess() {
  const box = $id('acc-brand-access'); if (!box) return;
  const bs = S.brands.filter(b => b.active);
  if (!bs.length) { box.innerHTML = '<div class="acc-empty">No hay marcas activas.</div>'; return; }
  const head = `<tr><th style="min-width:200px">Equipo / persona</th>${bs.map(b => `<th style="text-align:center">${chip(b.name, b.color)}</th>`).join('')}<th>Resultado</th></tr>`;
  const teamRows = S.teams.filter(t => t.active).map(t => `<tr><td><b style="font-size:12px">${chip(t.name, t.color)}</b> <span class="acc-muted">${t.members.length} miembros</span></td>
    ${bs.map(b => `<td style="text-align:center"><input type="checkbox" style="accent-color:var(--g7-accent);width:15px;height:15px;cursor:pointer" ${t.brands.includes(b.id) ? 'checked' : ''} data-on-change="accBrandAccess" data-change-1="s:team" data-change-2="j:${t.id}" data-change-3="j:${b.id}" data-change-4="checked"></td>`).join('')}<td class="acc-muted">Lo heredan sus miembros</td></tr>`).join('');
  const userRows = S.users.filter(u => u.active).map(u => {
    if (u.role === 'admin') return `<tr><td><div class="acc-user">${avatar(u, 22)}<b>${esc(uName(u))}</b></div></td>${bs.map(() => '<td style="text-align:center;color:var(--g7-accent)"><i class="fa-solid fa-crown" title="Admin: ve todas"></i></td>').join('')}<td>${chip('Todas · admin', null, 'inherit')}</td></tr>`;
    return `<tr><td><div class="acc-user">${avatar(u, 22)}<b>${esc(uName(u))}</b></div></td>
      ${bs.map(b => { const via = u.brands_team[b.id]; const direct = u.brands_direct.includes(b.id);
        return `<td style="text-align:center">${via !== undefined && !direct ? `<i class="fa-solid fa-circle-check" style="color:${esc(b.color)};opacity:.7;cursor:help" title="Vía ${esc(teamById(via)?.name || 'equipo')}"></i>` : `<input type="checkbox" style="accent-color:var(--g7-accent);width:15px;height:15px;cursor:pointer" ${direct ? 'checked' : ''} data-on-change="accBrandAccess" data-change-1="s:user" data-change-2="j:${u.id}" data-change-3="j:${b.id}" data-change-4="checked">`}</td>`; }).join('')}
      <td><div class="acc-chips">${brandsLabel(u)}</div></td></tr>`;
  }).join('');
  box.innerHTML = `<table class="adm-table acc-table-compact"><thead>${head}</thead><tbody>
    <tr><td colspan="${bs.length + 2}" class="acc-sub-h" style="background:var(--g7-bg-2);padding:8px 12px!important">Equipos</td></tr>${teamRows}
    <tr><td colspan="${bs.length + 2}" class="acc-sub-h" style="background:var(--g7-bg-2);padding:8px 12px!important">Personas</td></tr>${userRows}</tbody></table>`;
}
function accRenderBrandValues() {
  const tb = $id('acc-bv-tbody'); if (!tb || !BV) return;
  const only = $id('acc-bv-only').checked;
  const rows = BV.filter(v => !only || !v.brands.length);
  tb.innerHTML = rows.length ? rows.map(v => `<tr>
    <td><b style="font-size:12px">${v.value ? esc(v.value) : '<span class="acc-muted">(vacío)</span>'}</b></td>
    <td class="acc-muted">${esc(v.source)}</td><td style="text-align:right">${v.count.toLocaleString('es-AR')}</td>
    <td><div class="acc-chips">${v.brands.map(id => brandById(id)).filter(Boolean).map(b => chip(b.name, b.color)).join('') || '<span class="acc-chip" style="color:#fbbf24;border-color:rgba(245,158,11,.4)">Sin marca</span>'}</div></td>
    <td>${v.norm ? `<select class="adm-select" style="width:auto;min-width:130px" data-on-change="accBrandAssignValue" data-change-1="value" data-change-2="s:${esc(v.norm)}" data-change-3="this"><option value="">${v.brands.length ? 'Sumar a…' : 'Asignar a…'}</option>${S.brands.filter(b => !v.brands.includes(b.id)).map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select>` : ''}</td></tr>`).join('')
    : '<tr><td colspan="5" class="acc-empty">Todos los valores tienen marca ✓</td></tr>';
}
async function accBrandAssignValue(brandId, norm, sel) {
  if (!brandId) return;
  const b = brandById(brandId);
  if (!confirm(`Agregar la palabra clave "${norm}" a ${b.name}?\nTodo valor que la contenga pasa a ser de ${b.name}.`)) { sel.value = ''; return; }
  await accApi('brand_keyword_add', { brand_id: +brandId, keyword: norm });
  admToast(`Ahora es de ${b.name} ✓`); BV = null; accRefresh();
}
async function accBrandAccess(type, sid, bid, on) {
  await accApi('brand_access', { subject_type: type, subject_id: sid, brand_id: bid, on });
  accRefresh();
}
async function accBrandMove(id, dir) {
  const ids = S.brands.map(b => b.id); const i = ids.indexOf(id); const j = i + dir;
  if (j < 0 || j >= ids.length) return;
  [ids[i], ids[j]] = [ids[j], ids[i]];
  await accApi('brand_order', { ids }); accRefresh();
}
function accBrandOpen(id) {
  const b = id ? brandById(id) : null;
  DR.kind = 'brand'; DR.id = id; DR.tab = 'info';
  DR.draft = b ? { name: b.name, color: b.color, logo_url: b.logo_url || '', keywords: [...b.keywords], active: !!b.active } : { name: '', color: '#ff6600', logo_url: '', keywords: [], active: true };
  accDrawerOpen();
}
function accBrandTabs() { return [['info', 'Marca', 'fa-tag']]; }
function accBrandBody() {
  const d = DR.draft, b = DR.id ? brandById(DR.id) : null;
  const matches = BV ? BV.filter(v => d.keywords.some(k => v.norm.includes(k))) : [];
  return `<div class="adm-form-grid">
      <div><label class="adm-label">Nombre *</label><input class="adm-input" value="${esc(d.name)}" data-on-input="@set" data-input-1="s:DR.draft.name" data-input-2="value"></div>
      <div><label class="adm-label">Color</label><div style="display:flex;gap:6px"><input type="color" value="${esc(d.color)}" data-on-input="accColorInput" data-input-1="this" style="width:38px;height:33px;border:none;background:none;cursor:pointer"><input class="adm-input" value="${esc(d.color)}" data-on-input="@set" data-input-1="s:DR.draft.color" data-input-2="value"></div></div>
      <div style="grid-column:1/-1"><label class="adm-label">Logo (URL, opcional)</label><input class="adm-input" value="${esc(d.logo_url)}" data-on-input="@set" data-input-1="s:DR.draft.logo_url" data-input-2="value" placeholder="https://…"></div>
    </div>
    <div class="acc-field-row"><div><div class="lbl">Marca activa</div><div class="acc-help">Si la desactivas, su contenido pasa a contarse como "sin marca".</div></div><div class="ctl narrow">${toggle('acc-bd-active', d.active, '@set', 's:DR.draft.active', 'checked')}</div></div>
    <div class="acc-sub-h">Palabras clave</div>
    <div class="acc-help" style="margin-bottom:8px">Un proyecto o plataforma es de esta marca si contiene alguna (se ignoran mayúsculas, espacios y signos).</div>
    <div class="acc-chips" style="margin-bottom:8px">${d.keywords.map((k, i) => `<span class="acc-kw">${esc(k)}<button title="Quitar" data-on-click="accKwRemove" data-click-1="j:${i}">✕</button></span>`).join('') || '<span class="acc-muted">Sin palabras: se usará el nombre.</span>'}</div>
    <div style="display:flex;gap:6px"><input class="adm-input" id="acc-bd-kw" placeholder="ej: mimarca" data-on-keydown="accBrandAddKw" data-keydown-key="Enter"><button class="btn btn-ghost" data-on-click="accBrandAddKw"><i class="fa-solid fa-plus"></i> Agregar</button></div>
    ${BV ? `<div class="acc-sub-h">Con estas palabras reconoce (${matches.length})</div>
      <div class="acc-chips">${matches.slice(0, 40).map(v => `<span class="acc-chip" title="${esc(v.source)}">${esc(v.value || '(vacío)')} · ${v.count}</span>`).join('') || '<span class="acc-muted">Ningún valor actual.</span>'}</div>` : ''}
    ${b ? `<div class="acc-danger"><div style="font-size:12px;font-weight:700;color:#ef4444;margin-bottom:4px">Eliminar marca</div><div class="acc-help" style="margin-bottom:10px">Se borran sus asignaciones. El contenido no se toca: queda sin marca.</div>
      <button class="btn btn-danger btn-sm" data-on-click="accBrandDelete" data-click-1="j:${b.id}"><i class="fa-solid fa-trash"></i> Eliminar ${esc(b.name)}</button></div>` : ''}`;
}
function accBrandAddKw() {
  const i = $id('acc-bd-kw'); const k = i.value.toLowerCase().normalize('NFD').replace(/[^a-z0-9]/g, '');
  if (!k) return;
  if (!DR.draft.keywords.includes(k)) DR.draft.keywords.push(k);
  accDrawerRender(); setTimeout(() => $id('acc-bd-kw')?.focus(), 0);
}
async function accBrandSave() {
  const r = await accApi('brand_save', { id: DR.id || 0, ...DR.draft });
  admToast(DR.id ? 'Marca actualizada ✓' : 'Marca creada ✓');
  BV = null; await accRefresh(); accBrandOpen(r.id);
}
async function accBrandDelete(id) {
  if (!confirm(`¿Eliminar la marca "${brandById(id).name}"?`)) return;
  await accApi('brand_delete', { id }); admToast('Marca eliminada'); accDrawerClose(); BV = null; accRefresh();
}

// ═════════════════════════════════════════════════════════════
// MÓDULOS
// ═════════════════════════════════════════════════════════════
function accRenderModules() {
  const box = $id('acc-modules-list'); if (!box || !S) return;
  const groups = {};
  S.modules.forEach(m => (groups[m.group] = groups[m.group] || []).push(m));
  const total = S.users.filter(u => u.active).length;
  box.innerHTML = Object.entries(groups).map(([g, mods]) => `<div class="acc-mod-group">${esc(g)}</div>` + mods.map(m => `
    <div class="acc-mod">
      <div class="acc-mod-ic"><i class="fa-solid ${esc(m.icon)}"></i></div>
      <div style="min-width:0"><div class="acc-mod-name">${esc(m.label)} ${m.custom ? '<span class="acc-chip">personalizado</span>' : ''} ${m.brand_scoped ? '<span class="acc-chip" title="Filtra el contenido por marca"><i class="fa-solid fa-tags"></i> marca</span>' : ''}</div>
        <div class="acc-mod-path">${m.paths.map(p => '/toolz/' + esc(p)).join(' · ')}</div></div>
      ${m.core ? '<span class="acc-muted">Siempre disponible</span>' : seg(MODE_OPTS(m.mode), m.mode, 'accSetMode', 'm-', 's:' + m.id)}
      <div class="acc-mod-count">${m.allowed_count}/${total}<div class="acc-muted">personas</div></div>
      <button class="btn-icon" title="Detalles" data-on-click="accModuleOpen" data-click-1="s:${m.id}"><i class="fa-solid fa-sliders"></i></button>
    </div>`).join('')).join('');
}
async function accSetMode(mod, mode) {
  const m = modById(mod);
  if (mode === m.mode) return;
  if ((mode === 'off' || mode === 'admins' || mode === 'superadmins') && !confirm(`"${m.label}" quedará ${mode === 'off' ? 'apagado' : mode === 'admins' ? 'solo para admins' : 'solo para el superadmin: los demás admins tampoco van a poder entrar'}. ¿Seguir?`)) return;
  if (mode === 'teams') {
    const withGrant = S.teams.filter(t => t.grants.includes(mod + '.view'));
    if (!withGrant.length && !confirm(`Ningún equipo tiene permiso para "${m.label}". Si lo pasas a "Por equipos", solo entrarán los admins hasta que des permisos. ¿Seguir?`)) return;
  }
  await accApi('module_set', { module: mod, mode });
  admToast(`${m.label}: ${S.modes[mode].label}`); accRefresh();
}
function accModuleOpen(id) { DR.kind = 'module'; DR.id = id; DR.tab = 'access'; const m = modById(id); DR.draft = { label: m.label, icon: m.icon, description: m.description || '' }; accDrawerOpen(); }
function accModuleTabs() { return [['access', 'Quién entra', 'fa-users'], ['teams', 'Equipos', 'fa-people-group', modById(DR.id).core], ['exceptions', 'Excepciones', 'fa-user-pen', modById(DR.id).core], ['info', 'Nombre e ícono', 'fa-pen']]; }
function accModuleBody() {
  const m = modById(DR.id), d = DR.draft;
  if (DR.tab === 'info') {
    return `<div class="adm-form-grid">
      <div><label class="adm-label">Nombre</label><input class="adm-input" value="${esc(d.label)}" data-on-input="@set" data-input-1="s:DR.draft.label" data-input-2="value"></div>
      <div><label class="adm-label">Ícono (Font Awesome)</label><div style="display:flex;gap:6px;align-items:center"><span class="acc-mod-ic" style="flex-shrink:0"><i class="fa-solid ${esc(d.icon)}" id="acc-md-icprev"></i></span><input class="adm-input" value="${esc(d.icon)}" data-on-input="accIconInput" data-input-1="this" placeholder="fa-star"></div></div>
      <div style="grid-column:1/-1"><label class="adm-label">Descripción interna</label><textarea class="adm-input" data-on-input="@set" data-input-1="s:DR.draft.description" data-input-2="value" placeholder="Para qué sirve, quién lo usa…">${esc(d.description)}</textarea></div></div>
      <div class="acc-help">Rutas: ${m.paths.map(p => '<code>/toolz/' + esc(p) + '</code>').join(', ')}. El nombre se usa en este panel y en las pantallas de acceso denegado. El texto del menú se edita en Topbar & Logo.</div>
      ${m.custom ? `<div class="acc-danger"><div style="font-size:12px;font-weight:700;color:#ef4444;margin-bottom:8px">Módulo personalizado</div>
        <button class="btn btn-ghost btn-sm" data-on-click="accCustomModuleOpen" data-click-1="s:${m.id}"><i class="fa-solid fa-pen"></i> Editar rutas</button>
        <button class="btn btn-danger btn-sm" data-on-click="accCustomModuleDelete" data-click-1="s:${m.id}"><i class="fa-solid fa-trash"></i> Quitar del registro</button></div>` : ''}`;
  }
  if (DR.tab === 'teams') {
    return `<div class="acc-help" style="margin-bottom:10px">${m.mode === 'teams' ? 'Este módulo está en modo <b>Por equipos</b>: solo entran los equipos tildados en "Ver".' : `Este módulo está en modo <b>${esc(S.modes[m.mode].label)}</b>. Los permisos de equipo ${m.mode === 'everyone' ? 'no cambian quién entra, pero quedan listos si lo pasas a "Por equipos".' : 'no aplican.'}`}${(m.grant_actions || []).length ? ` <b>${(m.grant_actions).map(a => esc(actLabel(a))).join(', ')}</b> sí cuenta siempre: solo lo tienen los equipos tildados (o personas con excepción).` : ''}</div>
      ${S.teams.map(t => `<div class="acc-list-row">${chip(t.name, t.color)}<span class="acc-muted" style="flex:1">${t.members.length} miembros</span>
        ${m.actions.map(a => `<label class="acc-check"><input type="checkbox" ${t.grants.includes(m.id + '.' + a) ? 'checked' : ''} data-on-change="accTeamGrant" data-change-1="j:${t.id}" data-change-2="s:${m.id}" data-change-3="s:${a}" data-change-4="checked">${esc(actLabel(a))}</label>`).join('')}</div>`).join('') || '<div class="acc-empty">No hay equipos.</div>'}`;
  }
  if (DR.tab === 'exceptions') {
    const withOvr = S.users.filter(u => m.actions.some(a => u.overrides[m.id + '.' + a]));
    const candidates = S.users.filter(u => u.active && u.role !== 'admin');
    return `<div class="acc-help" style="margin-bottom:10px">Una excepción le da o le quita acceso a una persona puntual, sin importar su equipo.</div>
      <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
        <select class="adm-select" id="acc-me-user" style="flex:1;min-width:160px"><option value="">Elegir persona…</option>${candidates.map(u => `<option value="${u.id}">${esc(uName(u))}</option>`).join('')}</select>
        ${m.actions.length > 1 ? `<select class="adm-select" id="acc-me-act" style="width:auto">${m.actions.map(a => `<option value="${a}">${esc(actLabel(a))}</option>`).join('')}</select>` : ''}
        <button class="btn btn-ghost" data-on-click="accModuleException" data-click-1="s:allow"><i class="fa-solid fa-check" style="color:#22c55e"></i> Permitir</button>
        <button class="btn btn-ghost" data-on-click="accModuleException" data-click-1="s:deny"><i class="fa-solid fa-ban" style="color:#ef4444"></i> Denegar</button></div>
      ${withOvr.length ? withOvr.map(u => m.actions.filter(a => u.overrides[m.id + '.' + a]).map(a => `<div class="acc-list-row">${avatar(u)}<div style="flex:1"><div style="font-size:12px;font-weight:700">${esc(uName(u))}</div><div class="acc-muted">${esc(actLabel(a))}</div></div>
        ${seg(EFFECT_OPTS, u.overrides[m.id + '.' + a], 'accSetOverride', 'e-', 'j:' + u.id, 's:' + m.id, 's:' + a)}</div>`).join('')).join('') : '<div class="acc-empty">Sin excepciones para este módulo.</div>'}`;
  }
  // Quién entra
  const total = S.users.filter(u => u.active);
  const yes = total.filter(u => u.access[m.id + '.view'][0]), no = total.filter(u => !u.access[m.id + '.view'][0]);
  return `<div class="acc-field-row"><div><div class="lbl">Modo de acceso</div><div class="acc-help">${esc(S.modes[m.mode].desc)}</div></div>
      <div class="ctl narrow">${m.core ? '<span class="acc-muted">Siempre disponible</span>' : seg(MODE_OPTS(m.mode), m.mode, 'accSetMode', 'm-', 's:' + m.id)}</div></div>
    <div class="acc-sub-h">Entran (${yes.length})</div>
    ${yes.map(u => `<div class="acc-list-row">${avatar(u, 24)}<span style="flex:1;font-size:12px;font-weight:600">${esc(uName(u))}</span><span class="acc-pill ${u.role === 'admin' ? 'admin' : 'ok'}">${esc(u.access[m.id + '.view'][2])}</span></div>`).join('') || '<div class="acc-muted">Nadie.</div>'}
    <div class="acc-sub-h">No entran (${no.length})</div>
    ${no.map(u => `<div class="acc-list-row">${avatar(u, 24)}<span style="flex:1;font-size:12px;font-weight:600">${esc(uName(u))}</span><span class="acc-pill no">${esc(u.access[m.id + '.view'][2])}</span>
      <button class="btn btn-ghost btn-sm" data-on-click="accSetOverride" data-click-1="j:${u.id}" data-click-2="s:${m.id}" data-click-3="s:view" data-click-4="s:allow">Permitir</button></div>`).join('') || '<div class="acc-muted">Nadie: entran todos.</div>'}`;
}
async function accModuleException(effect) {
  const uid = +$id('acc-me-user').value; if (!uid) return admToast('Elige una persona', true);
  const act = $id('acc-me-act')?.value || 'view';
  await accSetOverride(uid, DR.id, act, effect);
}
async function accModuleSaveInfo() {
  await accApi('module_set', { module: DR.id, label: DR.draft.label, icon: DR.draft.icon, description: DR.draft.description });
  admToast('Módulo actualizado ✓'); accRefresh();
}
function accCustomModuleOpen(id) {
  const m = id ? modById(id) : null;
  DR.kind = 'custom'; DR.id = id || 0; DR.tab = 'info';
  DR.draft = m ? { id: m.id, label: m.label, icon: m.icon, group: m.group, paths: m.paths.join('\n') } : { id: '', label: '', icon: 'fa-puzzle-piece', group: 'Personalizados', paths: '' };
  accDrawerOpen();
}
function accCustomTabs() { return [['info', 'Módulo personalizado', 'fa-puzzle-piece']]; }
function accCustomBody() {
  const d = DR.draft;
  return `<div class="acc-info"><i class="fa-solid fa-circle-info" style="margin-top:2px"></i><div>Registra una herramienta nueva (por ejemplo una carpeta que subiste a /toolz/) para controlar quién entra. Las páginas tienen que usar el login de Toolz (<code>requireLogin()</code> o <code>getCurrentUser()</code>).</div></div>
    <div class="adm-form-grid">
      <div><label class="adm-label">Identificador *</label><input class="adm-input" value="${esc(d.id)}" ${DR.id ? 'disabled' : ''} data-on-input="accIdInput" data-input-1="this" placeholder="mi_herramienta"></div>
      <div><label class="adm-label">Nombre *</label><input class="adm-input" value="${esc(d.label)}" data-on-input="@set" data-input-1="s:DR.draft.label" data-input-2="value"></div>
      <div><label class="adm-label">Ícono</label><input class="adm-input" value="${esc(d.icon)}" data-on-input="@set" data-input-1="s:DR.draft.icon" data-input-2="value"></div>
      <div><label class="adm-label">Grupo</label><input class="adm-input" value="${esc(d.group)}" data-on-input="@set" data-input-1="s:DR.draft.group" data-input-2="value"></div>
      <div style="grid-column:1/-1"><label class="adm-label">Rutas (una por línea, relativas a /toolz/)</label><textarea class="adm-input" rows="4" data-on-input="@set" data-input-1="s:DR.draft.paths" data-input-2="value" placeholder="mi-herramienta/\notra_pagina.php">${esc(d.paths)}</textarea>
        <div class="acc-help">Termina con "/" para una carpeta completa.</div></div></div>`;
}
async function accCustomModuleSave() {
  const d = DR.draft;
  await accApi('module_custom_save', { id: d.id, label: d.label, icon: d.icon, group: d.group, paths: d.paths.split('\n') });
  admToast('Módulo guardado ✓'); const id = d.id; await accRefresh(); accModuleOpen(id);
}
async function accCustomModuleDelete(id) {
  if (!confirm('¿Quitar este módulo del registro? Se borran sus permisos. Los archivos no se tocan.')) return;
  await accApi('module_custom_delete', { id }); admToast('Módulo quitado'); accDrawerClose(); accRefresh();
}

// ═════════════════════════════════════════════════════════════
// MATRIZ
// ═════════════════════════════════════════════════════════════
function accRenderMatrix() {
  const box = $id('acc-matrix'); if (!box || !S) return;
  const q = $id('acc-m-q').value.trim(), team = $id('acc-m-team').value;
  const onlyR = $id('acc-m-restricted').checked, showAdm = $id('acc-m-admins').checked;
  const mods = S.modules.filter(m => !m.core && (!onlyR || m.mode !== 'everyone'));
  const users = S.users.filter(u => userMatches(u, q, team, 'active') && (showAdm || u.role !== 'admin'));
  if (!mods.length) { box.innerHTML = '<div class="acc-empty">Todos los módulos están abiertos a todos.</div>'; return; }
  box.innerHTML = `<table class="acc-matrix"><thead><tr><th class="first"><span class="acc-muted">${users.length} personas · ${mods.length} módulos</span></th>
    ${mods.map(m => `<th title="${esc(m.label)} · ${esc(S.modes[m.mode].label)}"><span class="vt" style="cursor:pointer" data-on-click="accModuleOpen" data-click-1="s:${m.id}"><i class="fa-solid ${esc(m.icon)}"></i>${esc(m.label)}${m.mode !== 'everyone' ? ' •' : ''}</span></th>`).join('')}</tr></thead>
    <tbody>${users.map(u => `<tr><td class="first"><div class="acc-user" style="cursor:pointer" data-on-click="accUserOpen" data-click-1="j:${u.id}">${avatar(u, 22)}<div style="min-width:0"><b>${esc(uName(u))}</b><small>${u.teams.map(t => teamById(t.team_id)?.name).filter(Boolean).join(', ') || 'sin equipo'}</small></div></div></td>
      ${mods.map(m => {
        const [ok, code, why] = u.access[m.id + '.view'];
        if (u.role === 'admin' && ok) return `<td class="acc-cell a" title="${u.is_super ? 'Superadmin' : 'Admin global'}"><i class="fa-solid fa-crown"></i></td>`;
        const ovr = u.overrides[m.id + '.view'];
        return `<td class="acc-cell ${ok ? 'y' : 'n'} ${ovr ? 'ovr' : ''}" title="${esc(m.label)}: ${esc(why)}" data-on-click="accMatrixClick" data-click-1="j:${u.id}" data-click-2="s:${m.id}"><i class="fa-solid ${ok ? 'fa-check' : 'fa-xmark'}"></i></td>`;
      }).join('')}</tr>`).join('')}</tbody></table>`;
}
async function accMatrixClick(uid, mod) {
  const u = userById(uid); const cur = u.overrides[mod + '.view'] || 'inherit';
  const next = { inherit: 'allow', allow: 'deny', deny: 'inherit' }[cur];
  const m = modById(mod);
  if ((m.mode === 'off' || m.mode === 'admins' || m.mode === 'superadmins') && next !== 'inherit') return admToast(`"${m.label}" está ${m.mode === 'off' ? 'apagado' : m.mode === 'admins' ? 'solo para admins' : 'solo para el superadmin'}: las excepciones no aplican`, true);
  await accSetOverride(uid, mod, 'view', next);
}

// ═════════════════════════════════════════════════════════════
// AJUSTES
// ═════════════════════════════════════════════════════════════
function accFieldHtml(f) {
  const v = S.settings[f.key] ?? f.default ?? '';
  const id = 'acc-set-' + f.key;
  let ctl;
  switch (f.type) {
    case 'toggle': ctl = toggle(id, v === '1', 'accSetDirty', 'this'); break;
    case 'select': ctl = `<select class="adm-select" id="${id}" data-on-change="accSetDirty" data-change-1="this">${Object.entries(f.options).map(([k, l]) => `<option value="${esc(k)}" ${String(k) === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`; break;
    case 'textarea': ctl = `<textarea class="adm-input" id="${id}" data-on-input="accSetDirty" data-input-1="this">${esc(v)}</textarea>`; break;
    case 'number': ctl = `<input class="adm-input" type="number" id="${id}" value="${esc(v)}" min="${f.min ?? ''}" max="${f.max ?? ''}" data-on-input="accSetDirty" data-input-1="this">`; break;
    case 'color': ctl = `<div style="display:flex;gap:6px"><input type="color" value="${esc(v)}" data-on-input="accMirror" data-input-1="this" data-input-2="s:${id}" style="width:38px;height:33px;border:none;background:none;cursor:pointer"><input class="adm-input" id="${id}" value="${esc(v)}" data-on-input="accSetDirty" data-input-1="this"></div>`; break;
    default: ctl = `<input class="adm-input" id="${id}" value="${esc(v)}" data-on-input="accSetDirty" data-input-1="this">`;
  }
  return `<div class="acc-field-row" data-key="${esc(f.key)}" data-type="${f.type}" style="${f.type === 'textarea' ? 'align-items:flex-start' : ''}"><div><div class="lbl">${esc(f.label)}</div>${f.help ? `<div class="acc-help">${esc(f.help)}</div>` : ''}</div><div class="ctl ${f.type === 'toggle' ? 'narrow' : ''}">${ctl}</div></div>`;
}
function accSetDirty(el) { el.closest('.adm-card')?.classList.add('dirty'); }
function accRenderSettings() {
  const box = $id('acc-settings'); if (!box || !S) return;
  const groups = S.settings_schema.filter(g => g.id !== 'brands');
  // Las tarjetas con cambios sin guardar no se redibujan: un refresco no pisa lo que el admin está editando
  if (box.children.length !== groups.length) box.innerHTML = groups.map(g => `<div class="adm-card" id="acc-card-${g.id}"></div>`).join('');
  groups.forEach(g => {
    const card = $id('acc-card-' + g.id);
    if (!card || card.classList.contains('dirty')) return;
    card.innerHTML = `<div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid ${esc(g.icon)}"></i> ${esc(g.title)} <span class="acc-dirty">· sin guardar</span></div>
      <button class="btn btn-primary btn-sm" data-on-click="accSaveSettingsCard" data-click-1="s:acc-card-${g.id}"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
    <div class="adm-card-body" style="padding-top:4px;padding-bottom:4px">${g.fields.map(accFieldHtml).join('')}</div>`;
  });
}
async function accSaveSettingsCard(cardId) {
  const card = $id(cardId); const values = {};
  card.querySelectorAll('.acc-field-row[data-key]').forEach(row => {
    const k = row.dataset.key, el = $id('acc-set-' + k);
    values[k] = row.dataset.type === 'toggle' ? (el.checked ? '1' : '0') : el.value;
  });
  if (values.maintenance === '1' && S.settings.maintenance !== '1' && !confirm('Con el modo mantenimiento solo los admins pueden usar la plataforma. ¿Activarlo?')) return;
  if (values.brands_enabled === '1' && S.settings.brands_enabled !== '1') {
    const sinMarca = S.users.filter(u => u.active && u.role !== 'admin' && !u.brands_direct.length && !Object.keys(u.brands_team).length);
    const msg = sinMarca.length ? `\n\n${sinMarca.length} personas no tienen marca asignada y ${(values.brand_no_assignment || S.settings.brand_no_assignment) === 'none' ? 'NO verán contenido de ninguna marca' : 'seguirán viendo todas'}.` : '';
    if (!confirm('Al activar la separación cada persona ve solo el contenido de sus marcas.' + msg + '\n\n¿Activar?')) return;
  }
  const r = await accApi('settings_save', { values });
  card.classList.remove('dirty');
  admToast(r.changed.length ? 'Guardado ✓' : 'Sin cambios');
  await accRefresh();
}
async function accRawLoad(toggleOpen) {
  const box = $id('acc-raw');
  if (toggleOpen && box.style.display !== 'none') { box.style.display = 'none'; return; }
  box.style.display = 'block';
  const d = await accApi('config_raw_list');
  box.innerHTML = `<div class="acc-warn"><i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i><div>Edición directa de <code>g7_config</code>. Úsalo solo si sabes qué hace la clave: un valor mal escrito puede romper la topbar o los permisos. Exporta un respaldo antes.</div></div>
    <div style="display:flex;gap:6px;margin-bottom:12px"><input class="adm-input" id="acc-raw-newk" placeholder="clave_nueva" style="max-width:200px"><input class="adm-input" id="acc-raw-newv" placeholder="valor"><button class="btn btn-ghost" data-on-click="accRawAdd"><i class="fa-solid fa-plus"></i></button></div>
    ${d.rows.map(r => `<div class="acc-list-row" style="align-items:flex-start"><code style="width:190px;flex-shrink:0;font-size:11px;padding-top:7px;word-break:break-all">${esc(r.cfg_key)}</code>
      <textarea class="adm-input" rows="${Math.min(6, Math.max(1, Math.ceil((r.cfg_value || '').length / 80)))}" data-k="${esc(r.cfg_key)}" style="font-family:ui-monospace,monospace;font-size:11px;min-height:32px">${esc(r.cfg_value)}</textarea>
      <button class="btn-icon" title="Guardar" data-on-click="accRawSaveRow" data-click-1="this" data-k="${esc(r.cfg_key)}"><i class="fa-solid fa-floppy-disk"></i></button>
      <button class="btn-icon" title="Borrar" style="color:#ef4444" data-on-click="accRawDeleteRow" data-click-1="this" data-k="${esc(r.cfg_key)}"><i class="fa-solid fa-trash"></i></button></div>`).join('')}`;
}
async function accRawSave(k, v) { if (!k) return; await accApi('config_raw_save', { key: k, value: v }); admToast('Clave guardada ✓'); accRawLoad(); accRefresh(); }
async function accRawDelete(k) { if (!confirm(`¿Borrar la clave "${k}"?`)) return; await accApi('config_raw_delete', { key: k }); admToast('Clave borrada'); accRawLoad(); accRefresh(); }

// ═════════════════════════════════════════════════════════════
// AUDITORÍA
// ═════════════════════════════════════════════════════════════
const AUDIT_LABELS = {
  'auth.login': 'Ingresó', 'auth.locked': 'Bloqueo por intentos fallidos', 'super.denied': 'Intento sin permiso de superadmin',
  'user.create': 'Creó usuario', 'user.update': 'Editó usuario', 'user.delete': 'Eliminó usuario', 'user.override': 'Excepción de acceso', 'user.overrides_clear': 'Quitó excepciones',
  'team.create': 'Creó equipo', 'team.update': 'Editó equipo', 'team.delete': 'Eliminó equipo', 'team.member': 'Cambió miembros', 'team.grant': 'Permiso de equipo',
  'brand.create': 'Creó marca', 'brand.update': 'Editó marca', 'brand.delete': 'Eliminó marca', 'brand.keyword_add': 'Palabra clave', 'brand.access': 'Acceso a marca',
  'module.update': 'Cambió módulo', 'module.custom_save': 'Módulo personalizado', 'module.custom_delete': 'Quitó módulo', 'settings.update': 'Ajustes',
  'config.raw_save': 'Clave de config', 'config.raw_delete': 'Borró clave', 'config.export': 'Exportó respaldo', 'config.import': 'Importó respaldo',
  'legacy.save_config': 'Ajustes (topbar/notif.)', 'legacy.save_monday': 'Monday API',
};
function auditTarget(t) {
  const [k, id] = String(t || '').split(':');
  if (k === 'user') return uName(userById(id)) || t;
  if (k === 'team') return teamById(id)?.name || t;
  if (k === 'brand') return brandById(id)?.name || t;
  return modById(t)?.label || t;
}
async function accAuditLoad() {
  const box = $id('acc-audit'); if (!box) return;
  if (S) accFillFilters();
  box.innerHTML = '<div class="acc-empty">Cargando…</div>';
  const d = await accApi('audit_list', { limit: 200, q: $id('acc-a-q').value.trim(), user_id: +$id('acc-a-user').value || 0 });
  box.innerHTML = d.rows.length ? d.rows.map(r => `<div class="log-item acc-audit-row" data-on-click="accToggleOpen">
    <span class="log-type" style="width:130px">${esc(AUDIT_LABELS[r.action] || r.action)}</span>
    <span class="log-user">${esc(r.display_name || r.username || 'Sistema')}</span>
    <span class="log-msg">${esc(S ? auditTarget(r.target) : r.target)}${r.detail ? `<div class="acc-json">${esc(JSON.stringify(JSON.parse(r.detail), null, 1))}</div>` : ''}</span>
    <span class="log-time">${(window.G7Time && G7Time.parse(r.created_at) || new Date(r.created_at.replace(' ', 'T'))).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short', timeZone: window.G7Time ? G7Time.cfg.tz : undefined })}</span></div>`).join('')
    : '<div class="acc-empty">Sin registros todavía. Acá aparece cada cambio hecho desde el panel.</div>';
}

// ═════════════════════════════════════════════════════════════
// RESPALDO
// ═════════════════════════════════════════════════════════════
async function accExport() {
  const d = await accApi('config_export');
  const blob = new Blob([JSON.stringify(d.data, null, 2)], { type: 'application/json' });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
  a.download = `toolz-config-${new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-')}.json`;
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  admToast('Respaldo descargado ✓');
}
let IMPORT_DATA = null;
function accImportRead(input) {
  const f = input.files[0]; const box = $id('acc-import-preview'); IMPORT_DATA = null;
  if (!f) { box.innerHTML = ''; return; }
  const rd = new FileReader();
  rd.onload = () => {
    try { IMPORT_DATA = JSON.parse(rd.result); } catch (e) { box.innerHTML = '<div class="acc-warn">El archivo no es JSON válido.</div>'; return; }
    if (IMPORT_DATA.format !== 'g7-toolz-config') { box.innerHTML = '<div class="acc-warn">No es un respaldo de configuración de Toolz.</div>'; IMPORT_DATA = null; return; }
    const D = IMPORT_DATA;
    box.innerHTML = `<div class="acc-info"><i class="fa-solid fa-file-circle-check" style="margin-top:2px"></i><div>Respaldo del <b>${esc(new Date(D.exported_at).toLocaleString('es-AR'))}</b>: ${Object.keys(D.config || {}).length} claves, ${(D.brands || []).length} marcas, ${(D.teams || []).length} equipos, ${(D.team_permissions || []).length + (D.user_permissions || []).length} permisos.</div></div>
      <div class="acc-sub-h">Qué restaurar</div>
      ${[['config', 'Ajustes y menú (g7_config)'], ['brands', 'Marcas y quién las ve'], ['permissions', 'Permisos de equipos y excepciones'], ['teams', 'Equipos y sus miembros']].map(([k, l]) => `<label class="acc-check" style="margin-bottom:8px"><input type="checkbox" class="acc-imp-part" value="${k}" ${k !== 'teams' ? 'checked' : ''}> ${l}</label>`).join('')}
      <div class="acc-warn" style="margin-top:10px"><i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i><div>Lo que restaures reemplaza lo actual. Exporta un respaldo antes por si quieres volver atrás.</div></div>
      <button class="btn btn-danger" data-on-click="accImportRun"><i class="fa-solid fa-upload"></i> Restaurar</button>`;
  };
  rd.readAsText(f);
}
async function accImportRun() {
  const parts = [...document.querySelectorAll('.acc-imp-part:checked')].map(i => i.value);
  if (!parts.length || !IMPORT_DATA) return admToast('Elige qué restaurar', true);
  if (!confirm('¿Restaurar ' + parts.join(', ') + '? Reemplaza la configuración actual.')) return;
  await accApi('config_import', { data: IMPORT_DATA, parts });
  admToast('Respaldo restaurado ✓'); $id('acc-import-preview').innerHTML = ''; $id('acc-import-file').value = ''; BV = null; accRefresh();
}

// ═════════════════════════════════════════════════════════════
// DRAWER
// ═════════════════════════════════════════════════════════════
const DRAWERS = {
  user: { tabs: accUserTabs, body: accUserBody, title: () => { const u = DR.id ? userById(DR.id) : null; return u ? `${avatar(u, 34)}<div style="min-width:0">${esc(uName(u))}<small>@${esc(u.username)} · ${u.role === 'admin' ? 'Admin global' : 'Usuario'}</small></div>` : '<div>Nuevo usuario<small>Completa los datos y guarda para configurar accesos</small></div>'; },
    foot: () => DR.tab === 'access' ? '<span class="acc-muted">Los cambios de esta pestaña se guardan solos.</span>' : `<button class="btn btn-ghost" data-on-click="accDrawerClose">Cancelar</button><button class="btn btn-primary" style="margin-left:auto" data-on-click="accUserSave"><i class="fa-solid fa-check"></i> ${DR.id ? 'Guardar cambios' : 'Crear usuario'}</button>` },
  team: { tabs: accTeamTabs, body: accTeamBody, title: () => { const t = DR.id ? teamById(DR.id) : null; return t ? `<span class="acc-mod-ic" style="background:${esc(t.color)}22;color:${esc(t.color)}"><i class="fa-solid fa-people-group"></i></span><div>${esc(t.name)}<small>${t.members.length} miembros</small></div>` : '<div>Nuevo equipo<small>Después puedes sumar miembros y permisos</small></div>'; },
    foot: () => DR.tab === 'info' ? `<button class="btn btn-ghost" data-on-click="accDrawerClose">Cancelar</button><button class="btn btn-primary" style="margin-left:auto" data-on-click="accTeamSave"><i class="fa-solid fa-check"></i> ${DR.id ? 'Guardar' : 'Crear equipo'}</button>` : '<span class="acc-muted">Los cambios de esta pestaña se guardan solos.</span>' },
  brand: { tabs: accBrandTabs, body: accBrandBody, title: () => { const b = DR.id ? brandById(DR.id) : null; return b ? `<span class="acc-mod-ic" style="background:${esc(b.color)}22;color:${esc(b.color)}"><i class="fa-solid fa-tag"></i></span><div>${esc(b.name)}<small>Marca</small></div>` : '<div>Nueva marca</div>'; },
    foot: () => `<button class="btn btn-ghost" data-on-click="accDrawerClose">Cancelar</button><button class="btn btn-primary" style="margin-left:auto" data-on-click="accBrandSave"><i class="fa-solid fa-check"></i> Guardar</button>` },
  module: { tabs: accModuleTabs, body: accModuleBody, title: () => { const m = modById(DR.id); return `<span class="acc-mod-ic"><i class="fa-solid ${esc(m.icon)}"></i></span><div>${esc(m.label)}<small>${esc(m.group)} · ${esc(S.modes[m.mode].label)}</small></div>`; },
    foot: () => DR.tab === 'info' ? `<button class="btn btn-primary" style="margin-left:auto" data-on-click="accModuleSaveInfo"><i class="fa-solid fa-check"></i> Guardar</button>` : '<span class="acc-muted">Los cambios se guardan solos.</span>' },
  custom: { tabs: accCustomTabs, body: accCustomBody, title: () => `<div>${DR.id ? 'Editar' : 'Nuevo'} módulo personalizado</div>`,
    foot: () => `<button class="btn btn-ghost" data-on-click="accDrawerClose">Cancelar</button><button class="btn btn-primary" style="margin-left:auto" data-on-click="accCustomModuleSave"><i class="fa-solid fa-check"></i> Guardar</button>` },
};
function accDrawerOpen() { accDrawerRender(); $id('acc-drawer').classList.add('open'); $id('acc-drawer-bg').classList.add('open'); $id('acc-drawer').setAttribute('aria-hidden', 'false'); }
function accDrawerClose() { $id('acc-drawer').classList.remove('open'); $id('acc-drawer-bg').classList.remove('open'); $id('acc-drawer').setAttribute('aria-hidden', 'true'); DR.kind = null; }
function accDrawerTab(t) { DR.tab = t; accDrawerRender(); }
function accDrawerRender() {
  const D = DRAWERS[DR.kind]; if (!D) return;
  if ((DR.kind === 'user' || DR.kind === 'team' || DR.kind === 'brand') && DR.id && !(DR.kind === 'user' ? userById(DR.id) : DR.kind === 'team' ? teamById(DR.id) : brandById(DR.id))) return accDrawerClose();
  const scroll = $id('acc-dr-body').scrollTop;
  $id('acc-dr-title').innerHTML = `<div style="display:flex;align-items:center;gap:10px">${D.title()}</div>`;
  const tabs = D.tabs();
  $id('acc-dr-tabs').innerHTML = tabs.length > 1 ? tabs.map(([k, l, ic, dis]) => `<button class="${DR.tab === k ? 'on' : ''}" ${dis ? 'disabled title="Guarda primero"' : ''} data-on-click="accDrawerTab" data-click-1="s:${k}"><i class="fa-solid ${ic}"></i> ${esc(l)}</button>`).join('') : '';
  $id('acc-dr-tabs').style.display = tabs.length > 1 ? '' : 'none';
  $id('acc-dr-body').innerHTML = D.body();
  $id('acc-dr-foot').innerHTML = D.foot();
  $id('acc-dr-body').scrollTop = scroll;
}
document.addEventListener('keydown', e => { if (e.key === 'Escape' && DR.kind) accDrawerClose(); });

// ── Arranque ──────────────────────────────────────────────────
window.addEventListener('load', async () => {
  const h = location.hash.slice(1);
  admShow(h && $id('sec-' + h) ? h : 'overview');
  try { await accRefresh(); } catch (e) { return; }
});

// ── Eventos que antes eran código en línea (CSP estricta, SEC-24 fase 2): misma lógica, con nombre ──
function accUserOpenNav(id) { accUserOpen(id); return false; }
function accTogglePw() { const i = $id('acc-ud-pw'); i.type = i.type === 'password' ? 'text' : 'password'; }
function accGoBrands() { accDrawerClose(); admShow('brands'); return false; }
function accGenNew() { GEN.pw = accGenMake(); accGenDraw(); }
function accGenSet(k, v) { GEN[k] = v; GEN.pw = accGenMake(); accGenDraw(); }
function accCopyCreds() { accCopy($id('acc-creds-txt').value, 'Datos copiados'); }
function accCredsClose() { $id('acc-creds').classList.remove('open'); }
function accColorInput(el) { DR.draft.color = el.value; el.nextElementSibling.value = el.value; }
function accBrandToggle(el, id) { DR.draft.brand_ids = el.checked ? [...new Set([...DR.draft.brand_ids, id])] : DR.draft.brand_ids.filter(x => x !== id); }
function accTeamAdd(tid) { accTeamMember(tid, +$id('acc-td-add').value, true); }
function accKwRemove(i) { DR.draft.keywords.splice(i, 1); accDrawerRender(); }
function accIconInput(el) { DR.draft.icon = el.value; $id('acc-md-icprev').className = 'fa-solid ' + el.value; }
function accIdInput(el) { DR.draft.id = el.value.toLowerCase().replace(/[^a-z0-9_]/g, ''); }
function accMirror(el, id) { $id(id).value = el.value; accSetDirty(el); }
function accRawAdd() { accRawSave($id('acc-raw-newk').value, $id('acc-raw-newv').value); }
function accRawSaveRow(el) { accRawSave(el.dataset.k, el.previousElementSibling.value); }
function accRawDeleteRow(el) { accRawDelete(el.dataset.k); }
function accToggleOpen() { this.classList.toggle('open'); }
function accSetInactive(el) { DR.draft.inactive = !el.checked; }
