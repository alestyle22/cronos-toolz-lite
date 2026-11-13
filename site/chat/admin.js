/**
 * admin.js — Panel de administración del chat. Todo sale de api.php (acciones admin_*); los permisos se verifican allá.
 */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const body = $('ca-body');
  const A = { users: {}, teams: [], cats: [], tab: 'stats', days: 30, chanQ: '', chanKind: '', auditOff: 0 };

  async function api(action, data = {}) {
    const r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CH': '1' }, body: JSON.stringify({ action, ...data }) });
    let d = null; try { d = await r.json(); } catch (e) {}
    if (!d || !d.ok) { const e = new Error((d && d.error) || 'Algo falló en el servidor'); e.status = r.status; throw e; }
    return d;
  }
  function toast(msg, err) {
    const t = document.createElement('div'); t.className = 'cc-toast' + (err ? ' err' : '');
    t.innerHTML = `<i class="fa-solid ${err ? 'fa-circle-exclamation' : 'fa-circle-check'}"></i>${esc(msg)}`;
    $('cc-toasts').appendChild(t); setTimeout(() => t.remove(), err ? 5000 : 2600);
  }
  const fail = e => toast(e.message, true);
  const uname = id => (A.users[id] || {}).name || (id ? '#' + id : 'Sistema');
  const D = s => new Date(String(s).replace(' ', 'T'));
  const when = s => !s ? '—' : window.G7Time ? G7Time.fmt(s, { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : D(s).toLocaleString('es-AR', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' });   // zona de quien mira
  const nf = n => Number(n).toLocaleString('es-AR');
  function csv(name, rows) {
    const txt = rows.map(r => r.map(v => { const s = String(v ?? ''); return /[",;\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }).join(';')).join('\r\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + txt], { type: 'text/csv;charset=utf-8' })); a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  }
  function confirmDlg(title, text, ok, danger) {
    return new Promise(res => {
      const ov = document.createElement('div'); ov.className = 'cc-ov';
      ov.innerHTML = `<div class="cc-dlg" role="dialog" aria-modal="true"><div class="cc-dh"><h3>${esc(title)}</h3></div><div class="cc-db"><p class="sub">${esc(text)}</p></div><div class="cc-df"><button class="cc-btn" data-n>Cancelar</button><button class="cc-btn ${danger ? 'red' : 'pri'}" data-y>${esc(ok)}</button></div></div>`;
      document.body.appendChild(ov);
      const done = v => { ov.remove(); res(v); };
      ov.querySelector('[data-n]').onclick = () => done(false); ov.querySelector('[data-y]').onclick = () => done(true);
      ov.addEventListener('mousedown', e => { if (e.target === ov) done(false); });
      ov.querySelector('[data-y]').focus();
    });
  }

  // ── Resumen ──
  async function stats() {
    body.innerHTML = '<div class="cc-sk"></div>'.repeat(4);
    let d; try { d = await api('admin_overview', { days: A.days }); } catch (e) { return fail(e); }
    const T = d.totals, max = Math.max(1, ...d.daily.map(x => x.n)), wmax = Math.max(1, ...d.weekly.map(x => x.n)), tmax = Math.max(1, ...d.top.map(x => x.n));
    const kinds = { channel: 'Canales', group: 'Grupos', dm: 'Mensajes directos' }, ktot = Object.values(d.kinds).reduce((a, b) => a + b, 0) || 1;
    body.innerHTML = `
      <div class="ca-bar"><select class="cc-in" id="ca-days" aria-label="Período">${[7, 30, 90, 180, 365].map(n => `<option value="${n}" ${n === A.days ? 'selected' : ''}>Últimos ${n} días</option>`).join('')}</select>
        <button class="cc-btn sm" id="ca-exp"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button></div>
      <div class="ca-grid">
        <div class="ca-k"><small>Mensajes</small><b>${nf(T.messages)}</b><span>en ${d.days} días</span></div>
        <div class="ca-k"><small>Personas que escribieron</small><b>${nf(T.senders)}</b><span>de ${nf(T.users)} activas en la intranet</span></div>
        <div class="ca-k"><small>Conectadas esta semana</small><b>${nf(T.active_7)}</b><span>abrieron el chat o la intranet</span></div>
        <div class="ca-k"><small>Archivos compartidos</small><b>${nf(T.files)}</b><span>${T.files_mb} MB en el período · ${T.storage_mb} MB en total</span></div>
      </div>
      <div class="ca-card"><h3><i class="fa-solid fa-chart-column" style="color:var(--g7-accent)"></i> Mensajes por día</h3>
        <div class="ca-bars" role="img" aria-label="Mensajes por día">${d.daily.map(x => `<div style="height:${Math.max(x.n ? 2 : 0, x.n / max * 100)}%" title="${esc(D(x.d).toLocaleDateString('es-AR'))}: ${x.n} mensajes · ${x.u} personas"></div>`).join('')}</div>
        <div class="ca-axis"><span>${esc(D(d.daily[0].d).toLocaleDateString('es-AR', { day: 'numeric', month: 'short' }))}</span><span>Hoy</span></div></div>
      <div class="ca-2">
        <div class="ca-card"><h3>Canales más usados</h3>${d.top.length ? d.top.map(x => `<div class="ca-hb"><span class="t">#${esc(x.name)}</span><span class="b"><i style="width:${x.n / tmax * 100}%"></i></span><span class="n">${nf(x.n)}</span></div>`).join('') : '<p class="ca-help">Todavía no hay mensajes en canales en este período.</p>'}</div>
        <div class="ca-card"><h3>Dónde se conversa</h3>${Object.entries(kinds).map(([k, l]) => `<div class="ca-hb"><span class="t">${l}</span><span class="b"><i style="width:${(d.kinds[k] || 0) / ktot * 100}%"></i></span><span class="n">${Math.round((d.kinds[k] || 0) / ktot * 100)}%</span></div>`).join('')}</div>
      </div>
      <div class="ca-2">
        <div class="ca-card"><h3>Participación por equipo</h3>${d.depts.length ? `<table class="ca-t"><thead><tr><th>Equipo</th><th class="num">Personas</th><th class="num">Escribieron</th><th class="num">Mensajes</th></tr></thead><tbody>${d.depts.map(x => `<tr><td>${esc(x.name)}</td><td class="num">${x.members}</td><td class="num">${x.writers} <span class="muted">(${x.members ? Math.round(x.writers / x.members * 100) : 0}%)</span></td><td class="num">${nf(x.n)}</td></tr>`).join('')}</tbody></table>` : '<p class="ca-help">No hay equipos con personas asignadas como equipo principal (Admin › Equipos).</p>'}</div>
        <div class="ca-card"><h3>Evolución semanal (últimos 6 meses)</h3>
          <div class="ca-bars" style="height:120px">${d.weekly.map(x => `<div style="height:${x.n / wmax * 100}%" title="Semana del ${esc(D(x.d).toLocaleDateString('es-AR'))}: ${x.n} mensajes · ${x.u} personas"></div>`).join('') || ''}</div>
          ${d.weekly.length ? `<div class="ca-axis"><span>${esc(D(d.weekly[0].d).toLocaleDateString('es-AR', { month: 'short', year: '2-digit' }))}</span><span>Esta semana</span></div>` : '<p class="ca-help">Sin datos todavía.</p>'}</div>
      </div>`;
    $('ca-days').onchange = e => { A.days = +e.target.value; stats(); };
    $('ca-exp').onclick = () => csv(`chat-uso-${d.days}dias.csv`, [['Fecha', 'Mensajes', 'Personas que escribieron'], ...d.daily.map(x => [x.d, x.n, x.u]), [], ['Canal', 'Mensajes', 'Personas'], ...d.top.map(x => ['#' + x.name, x.n, x.u]), [], ['Equipo', 'Personas', 'Escribieron', 'Mensajes'], ...d.depts.map(x => [x.name, x.members, x.writers, x.n])]);
  }

  // ── Canales y grupos ──
  async function channels() {
    body.innerHTML = '<div class="cc-sk"></div>'.repeat(5);
    let d; try { d = await api('admin_channels'); } catch (e) { return fail(e); }
    A.cats = d.categories; A.chans = d.channels;
    drawChannels();
  }
  function drawChannels() {
    const q = A.chanQ.toLowerCase();
    const rows = A.chans.filter(c => (!A.chanKind || (A.chanKind === 'archived' ? c.archived : c.kind === A.chanKind && !c.archived)) && (!q || (c.name + ' ' + c.topic).toLowerCase().includes(q)));
    const vis = c => c.kind === 'group' ? '<span class="ca-pill priv">Grupo</span>' : `<span class="ca-pill ${{ public: 'pub', private: 'priv', restricted: 'res' }[c.visibility]}">${{ public: 'Público', private: 'Privado', restricted: 'Restringido' }[c.visibility]}</span>`;
    body.innerHTML = `<div class="ca-bar"><input class="cc-in" id="ca-cq" placeholder="Buscar canal o grupo" value="${esc(A.chanQ)}"><select class="cc-in" id="ca-ck"><option value="">Activos (canales y grupos)</option><option value="channel" ${A.chanKind === 'channel' ? 'selected' : ''}>Solo canales</option><option value="group" ${A.chanKind === 'group' ? 'selected' : ''}>Solo grupos</option><option value="archived" ${A.chanKind === 'archived' ? 'selected' : ''}>Borrados</option></select>
      <button class="cc-btn sm" id="ca-cexp"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button></div>
      <div class="ca-card ca-wrap" style="padding:6px 8px"><table class="ca-t"><thead><tr><th>Nombre</th><th>Tipo</th><th>Categoría</th><th class="num">Miembros</th><th class="num">Mensajes</th><th>Último mensaje</th><th>Creador</th><th></th></tr></thead><tbody>
      ${rows.map(c => `<tr><td><b>${c.kind === 'channel' ? '#' : ''}${esc(c.name)}</b>${c.announce ? ' <span class="ca-pill">Anuncios</span>' : ''}${c.archived ? ' <span class="ca-pill arc">Borrado</span>' : ''}<div class="muted" style="font-size:12px">${esc(c.topic)}</div></td>
        <td>${vis(c)}</td><td>${c.kind === 'channel' ? esc((A.cats.find(k => +k.id === c.category_id) || {}).name || 'Sin categoría') : '—'}</td>
        <td class="num">${c.members}</td><td class="num">${nf(c.msgs)}</td><td class="muted">${when(c.last_msg_at)}</td><td>${esc(uname(c.owner))}</td>
        <td style="white-space:nowrap">${c.archived ? `<button class="cc-btn sm" data-restore="${c.id}">Restaurar</button>` : `<button class="cc-btn sm" data-chan="${c.id}">Administrar</button>`}</td></tr>`).join('') || '<tr><td colspan="8" class="muted" style="text-align:center;padding:24px">Nada coincide</td></tr>'}
      </tbody></table></div>`;
    $('ca-cq').oninput = e => { A.chanQ = e.target.value; const p = e.target.selectionStart; drawChannels(); const i = $('ca-cq'); i.focus(); i.setSelectionRange(p, p); };
    $('ca-ck').onchange = e => { A.chanKind = e.target.value; drawChannels(); };
    $('ca-cexp').onclick = () => csv('chat-canales.csv', [['Nombre', 'Tipo', 'Visibilidad', 'Categoría', 'Miembros', 'Mensajes', 'Último mensaje', 'Creador', 'Borrado'], ...A.chans.map(c => [c.name, c.kind === 'channel' ? 'Canal' : 'Grupo', c.visibility, (A.cats.find(k => +k.id === c.category_id) || {}).name || '', c.members, c.msgs, c.last_msg_at || '', uname(c.owner), c.archived ? 'sí' : ''])]);
  }
  async function manageChannel(id) {
    const c = A.chans.find(x => x.id === id); if (!c) return;
    let m; try { m = await api('admin_members', { conv: id }); } catch (e) { return fail(e); }
    const ov = document.createElement('div'); ov.className = 'cc-ov';
    const people = Object.values(A.users).filter(u => u.active).sort((a, b) => a.name.localeCompare(b.name));
    const draw = () => {
      const ids = m.members.map(x => x.user_id);
      ov.innerHTML = `<div class="cc-dlg" style="max-width:620px" role="dialog" aria-modal="true"><div class="cc-dh"><h3>${c.kind === 'channel' ? '#' : ''}${esc(c.name)}</h3><button class="cc-ib" data-x aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="cc-db">${c.kind === 'channel' ? `<form id="ca-cf">
          <div class="ca-pol"><div class="cc-fld"><label>Nombre</label><input class="cc-in" name="name" value="${esc(c.name)}" maxlength="60"></div>
          <div class="cc-fld"><label>Categoría</label><select class="cc-in" name="category_id"><option value="0">Sin categoría</option>${A.cats.map(k => `<option value="${k.id}" ${+k.id === c.category_id ? 'selected' : ''}>${esc(k.name)}</option>`).join('')}</select></div></div>
          <div class="cc-fld"><label>Descripción</label><input class="cc-in" name="topic" value="${esc(c.topic)}" maxlength="300"></div>
          <div class="cc-fld"><label>Acceso</label><select class="cc-in" name="visibility"><option value="public" ${c.visibility === 'public' ? 'selected' : ''}>Público: cualquiera se une</option><option value="private" ${c.visibility === 'private' ? 'selected' : ''}>Privado: por invitación</option><option value="restricted" ${c.visibility === 'restricted' ? 'selected' : ''}>Restringido: solo equipos elegidos</option></select></div>
          <div class="cc-fld" id="ca-teams" ${c.visibility === 'restricted' ? '' : 'hidden'}><label>Equipos con acceso</label><div class="cc-pick" style="max-height:140px">${A.teams.map(t => `<label><input type="checkbox" name="team" value="${t.id}" ${c.allowed_teams.includes(+t.id) ? 'checked' : ''}><span class="cc-rt"><b>${esc(t.name)}</b></span></label>`).join('') || '<div class="cc-empty">No hay equipos</div>'}</div></div>
          <label class="cc-check"><input type="checkbox" name="announce" ${c.announce ? 'checked' : ''}> Canal de anuncios: solo publican sus administradores</label>
          <button class="cc-btn pri sm" type="submit" style="margin:6px 0 14px">Guardar cambios</button></form>` : ''}
          <div class="cc-fld"><label>Miembros (${m.members.length})</label>
          <div style="display:flex;gap:6px;margin-bottom:8px"><select class="cc-in" id="ca-add"><option value="0">Sumar a…</option>${people.filter(u => !ids.includes(u.id)).map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select><button class="cc-btn sm" id="ca-addb">Sumar</button></div>
          <div class="cc-pick" style="max-height:260px">${m.members.map(x => `<div class="cc-mem"><span class="cc-rt"><b>${esc(uname(x.user_id))}</b><small>${{ owner: 'Creador', admin: 'Administrador', member: 'Miembro' }[x.role]} · desde ${when(x.joined_at)}</small></span>
            <select class="cc-in" data-role="${x.user_id}" style="width:auto;height:30px"><option value="member" ${x.role === 'member' ? 'selected' : ''}>Miembro</option><option value="admin" ${x.role === 'admin' ? 'selected' : ''}>Administrador</option><option value="owner" ${x.role === 'owner' ? 'selected' : ''}>Creador</option></select>
            <button class="cc-ib" data-rm="${x.user_id}" title="Sacar" aria-label="Sacar a ${esc(uname(x.user_id))}"><i class="fa-solid fa-user-xmark"></i></button></div>`).join('')}</div></div>
          <p class="ca-help">Los cambios se registran en el historial de la conversación y en el Registro.</p></div></div>`;
      ov.querySelector('[data-x]').onclick = () => { ov.remove(); channels(); };
      const f = ov.querySelector('#ca-cf');
      if (f) {
        f.visibility.onchange = () => { ov.querySelector('#ca-teams').hidden = f.visibility.value !== 'restricted'; };
        f.onsubmit = async e => {
          e.preventDefault();
          const data = { conv: id, name: f.name.value, topic: f.topic.value, category_id: +f.category_id.value, visibility: f.visibility.value, announce: f.announce.checked, allowed_teams: [...f.querySelectorAll('input[name=team]:checked')].map(i => +i.value) };
          try { await api('conv_update', data); Object.assign(c, { name: f.name.value, topic: f.topic.value, category_id: data.category_id || null, visibility: data.visibility, announce: data.announce, allowed_teams: data.allowed_teams }); toast('Canal actualizado'); } catch (err) { fail(err); }
        };
      }
      ov.querySelector('#ca-addb').onclick = async () => { const u = +ov.querySelector('#ca-add').value; if (!u) return; try { await api('admin_member_set', { conv: id, user_id: u, op: 'add' }); m = await api('admin_members', { conv: id }); draw(); toast('Sumado'); } catch (e) { fail(e); } };
      ov.querySelectorAll('[data-role]').forEach(s => { s.onchange = async () => { try { await api('admin_member_set', { conv: id, user_id: +s.dataset.role, op: s.value }); m = await api('admin_members', { conv: id }); draw(); toast('Rol actualizado'); } catch (e) { fail(e); } }; });
      ov.querySelectorAll('[data-rm]').forEach(b => { b.onclick = async () => { if (!(await confirmDlg('¿Sacarlo de la conversación?', `${uname(+b.dataset.rm)} deja de verla.`, 'Sacar', true))) return; try { await api('admin_member_set', { conv: id, user_id: +b.dataset.rm, op: 'remove' }); m = await api('admin_members', { conv: id }); draw(); } catch (e) { fail(e); } }; });
    };
    draw();
    document.body.appendChild(ov);
  }

  // ── Categorías ──
  async function cats() {
    try { const d = await api('admin_channels'); A.cats = d.categories; A.chans = d.channels; } catch (e) { return fail(e); }
    const n = id => A.chans.filter(c => c.kind === 'channel' && !c.archived && c.category_id === +id).length;
    body.innerHTML = `<div class="ca-card"><h3>Categorías de canales<span class="sp"></span><button class="cc-btn pri sm" id="ca-newcat"><i class="fa-solid fa-plus"></i> Nueva categoría</button></h3>
      <p class="ca-help" style="margin:-4px 0 12px">Ordenan los canales en la lista de todos. También puedes arrastrarlas directamente en el chat.</p>
      ${A.cats.map((k, i) => `<div class="cc-mem" style="border-bottom:1px solid var(--g7-border-2)"><span style="display:flex;flex-direction:column"><button class="cc-ib" data-up="${i}" ${i ? '' : 'disabled'} aria-label="Subir" style="height:18px"><i class="fa-solid fa-chevron-up" style="font-size:10px"></i></button><button class="cc-ib" data-down="${i}" ${i < A.cats.length - 1 ? '' : 'disabled'} aria-label="Bajar" style="height:18px"><i class="fa-solid fa-chevron-down" style="font-size:10px"></i></button></span>
        <span class="cc-rt"><b>${esc(k.name)}</b><small>${n(k.id)} ${n(k.id) === 1 ? 'canal' : 'canales'}</small></span><button class="cc-btn sm" data-ren="${k.id}">Renombrar</button><button class="cc-ib" data-del="${k.id}" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button></div>`).join('') || '<p class="ca-help">No hay categorías.</p>'}</div>`;
    const save = async (id, name) => { try { await api('cat_save', { id, name }); cats(); toast('Guardado'); } catch (e) { fail(e); } };
    $('ca-newcat').onclick = () => { const nm = prompt('Nombre de la categoría (ej: Marketing)'); if (nm && nm.trim()) save(0, nm.trim()); };
    body.querySelectorAll('[data-ren]').forEach(b => { b.onclick = () => { const k = A.cats.find(x => +x.id === +b.dataset.ren); const nm = prompt('Nuevo nombre', k.name); if (nm && nm.trim()) save(k.id, nm.trim()); }; });
    body.querySelectorAll('[data-del]').forEach(b => { b.onclick = async () => { if (!(await confirmDlg('¿Borrar la categoría?', 'Sus canales quedan sin categoría; no se borra ningún canal.', 'Borrar', true))) return; try { await api('cat_delete', { id: +b.dataset.del }); cats(); } catch (e) { fail(e); } }; });
    const move = async (i, d) => { const o = A.cats.map(k => +k.id); [o[i], o[i + d]] = [o[i + d], o[i]]; try { await api('order', { categories: o }); cats(); } catch (e) { fail(e); } };
    body.querySelectorAll('[data-up]').forEach(b => { b.onclick = () => move(+b.dataset.up, -1); });
    body.querySelectorAll('[data-down]').forEach(b => { b.onclick = () => move(+b.dataset.down, 1); });
  }

  // ── Personas ──
  async function people() {
    body.innerHTML = '<div class="cc-sk"></div>'.repeat(5);
    let d; try { d = await api('admin_users'); } catch (e) { return fail(e); }
    const rows = d.users.map(x => ({ ...x, u: A.users[x.id] || { name: '#' + x.id } }));
    body.innerHTML = `<div class="ca-bar"><span class="ca-help" style="margin:0;flex:1">Los usuarios, sus permisos y su equipo se administran en la intranet: el chat usa esas mismas cuentas.</span>
      <a class="cc-btn sm" href="/toolz/admin.php" target="_blank" rel="noopener"><i class="fa-solid fa-user-gear"></i> Usuarios y permisos</a><button class="cc-btn sm" id="ca-pexp"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button></div>
      <div class="ca-card ca-wrap" style="padding:6px 8px"><table class="ca-t"><thead><tr><th>Persona</th><th>Equipo</th><th>Estado</th><th class="num">Mensajes (30 días)</th><th>Último mensaje</th><th>Última conexión</th><th class="num">Conversaciones</th><th class="num">Archivos</th></tr></thead><tbody>
      ${rows.map(x => `<tr${x.active ? '' : ' class="muted"'}><td><b>${esc(x.u.name)}</b><div class="muted" style="font-size:12px">${esc(x.u.position || '@' + (x.u.username || ''))}</div></td><td>${esc(x.u.dept || '—')}</td>
        <td>${x.active ? '<span class="ca-pill pub">Activo</span>' : '<span class="ca-pill arc">Desactivado</span>'}</td><td class="num">${nf(x.n30)}</td><td class="muted">${when(x.last_msg)}</td><td class="muted">${when(x.last_seen)}</td><td class="num">${x.convs}</td><td class="num">${x.mb} MB</td></tr>`).join('')}
      </tbody></table></div>`;
    $('ca-pexp').onclick = () => csv('chat-personas.csv', [['Persona', 'Usuario', 'Equipo', 'Activo', 'Mensajes 30 días', 'Último mensaje', 'Última conexión', 'Conversaciones', 'Archivos MB'], ...rows.map(x => [x.u.name, x.u.username || '', x.u.dept || '', x.active ? 'sí' : 'no', x.n30, x.last_msg || '', x.last_seen || '', x.convs, x.mb])]);
  }

  // ── Políticas ──
  async function policy() {
    let d; try { d = await api('admin_policy'); } catch (e) { return fail(e); }
    const P = d.policy;
    body.innerHTML = `<form class="ca-card" id="ca-pf"><h3><i class="fa-solid fa-shield-halved" style="color:var(--g7-accent)"></i> Políticas del chat</h3>
      <div class="ca-pol">
        <div class="cc-fld"><label>Tamaño máximo por archivo (MB)</label><input type="number" class="cc-in" name="max_mb" min="1" max="200" value="${P.max_mb}"><div class="ca-help">Los archivos grandes se suben en partes, así que no depende del límite del hosting.</div></div>
        <div class="cc-fld"><label>Espacio por persona (MB)</label><input type="number" class="cc-in" name="quota_mb" min="0" max="100000" value="${P.quota_mb}"><div class="ca-help">0 = sin límite. Cuenta lo que cada persona subió al chat.</div></div>
        <div class="cc-fld"><label>Retención de mensajes (días)</label><input type="number" class="cc-in" name="retention_days" min="0" max="3650" value="${P.retention_days}"><div class="ca-help">0 = se conservan siempre. Si pones un número, los mensajes más viejos y sus archivos se borran definitivamente.</div></div>
        <div class="cc-fld"><label>Freno al spam (mensajes cada 10 s)</label><input type="number" class="cc-in" name="rate_per_10s" min="3" max="100" value="${P.rate_per_10s}"><div class="ca-help">Si alguien manda más que esto, tiene que esperar unos segundos.</div></div>
      </div>
      <div class="cc-fld"><label>Tipos de archivo bloqueados</label><div class="ca-ext">${d.all_ext.map(x => `<label><input type="checkbox" name="ext" value="${x}" ${P.blocked_ext.includes(x) ? 'checked' : ''}>.${x}</label>`).join('')}</div>
        <div class="ca-help">Solo se aceptan estos tipos; marca los que no quieras permitir. Ejecutables, scripts y HTML nunca se aceptan, y el tipo real se verifica por el contenido del archivo.</div></div>
      <button class="cc-btn pri" type="submit">Guardar políticas</button></form>`;
    $('ca-pf').onsubmit = async e => {
      e.preventDefault();
      const f = e.target, pol = { max_mb: +f.max_mb.value, quota_mb: +f.quota_mb.value, retention_days: +f.retention_days.value, rate_per_10s: +f.rate_per_10s.value, blocked_ext: [...f.querySelectorAll('input[name=ext]:checked')].map(i => i.value) };
      if (pol.retention_days > 0 && pol.retention_days !== P.retention_days && !(await confirmDlg('¿Activar la retención?', `Los mensajes de más de ${pol.retention_days} días y sus archivos se van a borrar para siempre.`, 'Activar', true))) return;
      try { await api('admin_policy', { policy: pol, confirm_short: 1 }); toast('Políticas guardadas'); policy(); } catch (err) { fail(err); }
    };
  }

  // ── Registro ──
  const ACT = { msg_delete: 'Borró un mensaje ajeno', conv_update: 'Cambió una conversación', conv_delete: 'Borró una conversación', conv_restore: 'Restauró una conversación', member_remove: 'Sacó a alguien', member_role: 'Cambió un rol',
    cat_save: 'Guardó una categoría', cat_delete: 'Borró una categoría', policy: 'Cambió las políticas', retention: 'Retención automática', admin_member_add: 'Admin: sumó a alguien', admin_member_remove: 'Admin: sacó a alguien', admin_member_admin: 'Admin: nombró administrador', admin_member_member: 'Admin: quitó administrador', admin_member_owner: 'Admin: cambió el creador' };
  async function audit(more) {
    if (!more) { A.auditOff = 0; A.audit = []; body.innerHTML = '<div class="cc-sk"></div>'.repeat(5); }
    let d; try { d = await api('admin_audit', { offset: A.auditOff, filter: A.auditF || '' }); } catch (e) { return fail(e); }
    A.audit = A.audit.concat(d.items); A.auditOff += d.items.length;
    body.innerHTML = `<div class="ca-bar"><select class="cc-in" id="ca-af"><option value="">Todas las acciones</option>${Object.entries(ACT).map(([k, l]) => `<option value="${k}" ${A.auditF === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select><button class="cc-btn sm" id="ca-aexp"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button></div>
      <div class="ca-card ca-wrap" style="padding:6px 8px"><table class="ca-t"><thead><tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>Dónde</th><th>Detalle</th><th>IP</th></tr></thead><tbody>
      ${A.audit.map(x => `<tr><td class="muted" style="white-space:nowrap">${when(x.at)}</td><td>${esc(uname(x.user_id))}</td><td>${esc(ACT[x.action] || x.action)}</td><td>${esc(x.conv)}${x.target ? `<div class="muted" style="font-size:12px">${esc(x.target)}</div>` : ''}</td><td style="max-width:380px;font-size:12px">${esc((x.detail || '').slice(0, 400))}</td><td class="muted">${esc(x.ip)}</td></tr>`).join('') || '<tr><td colspan="6" class="muted" style="text-align:center;padding:24px">Todavía no hay acciones registradas</td></tr>'}
      </tbody></table>${d.more ? '<div class="cc-more"><button class="cc-btn sm" id="ca-amore">Ver más</button></div>' : ''}</div>`;
    $('ca-af').onchange = e => { A.auditF = e.target.value; audit(); };
    if ($('ca-amore')) $('ca-amore').onclick = () => audit(true);
    $('ca-aexp').onclick = () => csv('chat-registro.csv', [['Fecha', 'Persona', 'Acción', 'Conversación', 'Objetivo', 'Detalle', 'IP'], ...A.audit.map(x => [x.at, uname(x.user_id), ACT[x.action] || x.action, x.conv, x.target, x.detail || '', x.ip])]);
  }

  const TABS = { stats, channels, cats, people, policy, audit };
  document.addEventListener('click', e => {
    const t = e.target.closest('[data-tab]');
    if (t) { document.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('on', b === t)); A.tab = t.dataset.tab; history.replaceState(null, '', '#' + A.tab); TABS[A.tab](); return; }
    const ch = e.target.closest('[data-chan]'); if (ch) { manageChannel(+ch.dataset.chan); return; }
    const rs = e.target.closest('[data-restore]');
    if (rs) (async () => { if (!(await confirmDlg('¿Restaurar la conversación?', 'Vuelve a estar activa y quedas como su creador para sumar a quien corresponda.', 'Restaurar'))) return; try { await api('admin_conv_restore', { conv: +rs.dataset.restore }); toast('Restaurada'); channels(); } catch (err) { fail(err); } })();
  });

  (async () => {
    try { const d = await api('boot'); d.users.forEach(u => { A.users[u.id] = u; }); A.teams = d.teams; A.cats = d.categories; } catch (e) { body.innerHTML = `<div class="cc-empty"><b>No se pudo cargar</b>${esc(e.message)}</div>`; return; }
    const h = location.hash.slice(1);
    if (TABS[h]) document.querySelector(`[data-tab="${h}"]`).click(); else stats();
  })();
})();
