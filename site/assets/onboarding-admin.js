/* onboarding-admin.js — Panel del onboarding (onboarding_admin.php). Habla con onboarding_api.php (admin_state, admin_cfg, admin_action). */
'use strict';
(function () {
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let tT; const toast = (m, err = false) => { const t = $('ob-toast'); t.textContent = m; t.classList.toggle('err', err); t.classList.add('show'); clearTimeout(tT); tT = setTimeout(() => t.classList.remove('show'), err ? 4500 : 2600); };
  async function api(action, data = {}) {
    const r = await fetch('/toolz/onboarding_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-OB': '1' }, body: JSON.stringify({ action, ...data }) });
    const j = await r.json().catch(() => ({ error: 'Respuesta inesperada' }));
    if (!r.ok || j.error) throw new Error(j.error || 'No se pudo completar');
    return j;
  }
  const A = { tab: 'people', f: 'all', q: '', sel: new Set(), d: null };
  const ST_COLORS = { pending: '#94a3b8', progress: '#3b82f6', security: '#f59e0b', done: '#22c55e' };
  const ago = s => { if (!s) return '—'; const d = (Date.now() - new Date(s.replace(' ', 'T')).getTime()) / 864e5; return d < 1 ? 'hoy' : d < 2 ? 'ayer' : d < 30 ? `hace ${Math.floor(d)} días` : s.slice(0, 10); };
  document.querySelectorAll('#oba-tabs [data-t]').forEach(b => b.addEventListener('click', () => { A.tab = b.dataset.t; document.querySelectorAll('#oba-tabs [data-t]').forEach(x => x.classList.toggle('on', x === b)); draw(); }));
  load();
  async function load() { try { A.d = await api('admin_state'); draw(); } catch (e) { $('oba-main').innerHTML = `<div class="ob-card">${esc(e.message)}</div>`; } }
  function draw() { A.tab === 'cfg' ? drawCfg() : drawPeople(); }

  function drawPeople() {
    const d = A.d, s = d.stats;
    const list = d.users.filter(u => (A.f === 'all' || (A.f === 'nodone' ? u.status !== 'done' : A.f === 'no2fa' ? !u.twofa : A.f === 'missing' ? u.missing.length : u.status === A.f)) && (!A.q || (u.name + ' ' + u.username).toLowerCase().includes(A.q)));
    const kpi = (k, l, n, c, ic) => `<button type="button" class="oba-kpi${A.f === k ? ' on' : ''}" data-f="${k}" style="--c:${c}"><span><i class="fa-solid ${ic}"></i></span><div><b>${n}</b><small>${l}</small></div></button>`;
    $('oba-main').innerHTML = `<div class="oba-kpis">
        ${kpi('all', 'Personas activas', s.total, '#64748b', 'fa-users')}${kpi('done', `Completaron (${s.done_pct}%)`, s.by.done, ST_COLORS.done, 'fa-circle-check')}
        ${kpi('progress', 'En progreso', s.by.progress, ST_COLORS.progress, 'fa-person-walking')}${kpi('security', 'Pendiente de seguridad', s.by.security, ST_COLORS.security, 'fa-shield-halved')}
        ${kpi('pending', 'No iniciaron', s.by.pending, ST_COLORS.pending, 'fa-hourglass-start')}${kpi('no2fa', `Sin 2FA (con: ${s.twofa_pct}%)`, s.total - s.twofa, '#ef4444', 'fa-unlock')}</div>
      <div class="oba-meter" title="Completaron"><i style="--w:${s.done_pct}%"></i></div>
      <div class="oba-bar"><label class="oba-search"><i class="fa-solid fa-magnifying-glass"></i><input id="oba-q" placeholder="Buscar persona…" value="${esc(A.q)}"></label>
        <select id="oba-f" class="ob-in sm">${[['all', 'Todas'], ['nodone', 'Sin completar'], ['missing', 'Con datos faltantes'], ['no2fa', 'Sin 2FA'], ['pending', 'No iniciado'], ['progress', 'En progreso'], ['security', 'Pendiente de seguridad'], ['done', 'Completado']].map(([k, l]) => `<option value="${k}"${A.f === k ? ' selected' : ''}>${l}</option>`).join('')}</select>
        <span class="sp"></span><span class="oba-selinfo" id="oba-selinfo"></span>
        <div class="oba-acts" id="oba-acts">${[['force', 'fa-rotate', 'Pedir onboarding completo'], ['missing', 'fa-user-pen', 'Pedir lo que falta'], ['require_2fa', 'fa-shield-halved', 'Exigir 2FA'], ['reset', 'fa-arrow-rotate-left', 'Reiniciar'], ['remind', 'fa-bell', 'Recordar'], ['release', 'fa-check', 'Dar por completo']].map(([k, i, l]) => `<button type="button" class="ob-btn sm" data-op="${k}" disabled><i class="fa-solid ${i}"></i> ${l}</button>`).join('')}</div></div>
      <div class="oba-tablewrap"><table class="oba-table"><thead><tr><th><input type="checkbox" id="oba-all" aria-label="Elegir todas"></th><th>Persona</th><th>Estado</th><th>Paso</th><th>Datos</th><th>2FA</th><th>Empezó</th><th>Terminó</th></tr></thead><tbody>
      ${list.map(u => `<tr class="${A.sel.has(u.id) ? 'sel' : ''}"><td><input type="checkbox" data-id="${u.id}"${A.sel.has(u.id) ? ' checked' : ''} aria-label="Elegir"></td>
        <td><div class="oba-p"><span class="oba-av"${u.avatar ? ` style="background-image:url('${esc(u.avatar)}')"` : ''}>${u.avatar ? '' : esc(u.name[0] || '?')}</span><div><b>${esc(u.name)}</b><small>@${esc(u.username)}${u.role === 'admin' ? ' · admin' : ''}</small></div></div></td>
        <td><span class="oba-st" style="--c:${ST_COLORS[u.status]}">${esc(d.status[u.status])}</span>${u.auto ? '<small class="oba-mut" title="Ya tenía usuario: no se le mostró">existente</small>' : ''}${u.forced ? `<small class="oba-mut" title="${esc(u.force_reason)}">pedido por admin</small>` : ''}</td>
        <td>${u.status === 'done' ? '—' : ['Bienvenida', 'Perfil', 'Preferencias', 'Seguridad', 'Resumen'][u.last_step] || '—'}</td>
        <td>${u.missing.length ? `<span class="oba-miss" title="${esc(u.missing.map(f => d.fields[f]).join(', '))}"><i class="fa-solid fa-triangle-exclamation"></i> ${u.missing.length} faltan</span>` : '<i class="fa-solid fa-check oba-okc"></i>'}</td>
        <td>${u.twofa ? '<span class="oba-2fa on"><i class="fa-solid fa-shield-halved"></i> Activa</span>' : u.twofa_required ? '<span class="oba-2fa req"><i class="fa-solid fa-lock-open"></i> Exigida</span>' : `<span class="oba-2fa">${u.skipped ? 'La dejó para después' : 'No'}</span>`}</td>
        <td class="oba-mut">${ago(u.started_at)}</td><td class="oba-mut">${ago(u.completed_at)}</td></tr>`).join('') || '<tr><td colspan="8" class="oba-empty">Nadie con este filtro.</td></tr>'}</tbody></table></div>`;
    const syncSel = () => { const n = A.sel.size; $('oba-selinfo').textContent = n ? `${n} elegida${n > 1 ? 's' : ''}` : ''; document.querySelectorAll('#oba-acts [data-op]').forEach(b => { b.disabled = !n; }); };
    syncSel();
    document.querySelectorAll('.oba-kpi[data-f]').forEach(b => b.addEventListener('click', () => { A.f = b.dataset.f; drawPeople(); }));
    $('oba-f').addEventListener('change', e => { A.f = e.target.value; drawPeople(); });
    let tq; $('oba-q').addEventListener('input', e => { clearTimeout(tq); tq = setTimeout(() => { A.q = e.target.value.trim().toLowerCase(); drawPeople(); $('oba-q').focus(); }, 200); });
    document.querySelectorAll('tbody [data-id]').forEach(c => c.addEventListener('change', () => { const id = +c.dataset.id; c.checked ? A.sel.add(id) : A.sel.delete(id); c.closest('tr').classList.toggle('sel', c.checked); syncSel(); }));
    $('oba-all').addEventListener('change', e => { list.forEach(u => e.target.checked ? A.sel.add(u.id) : A.sel.delete(u.id)); drawPeople(); });
    document.querySelectorAll('#oba-acts [data-op]').forEach(b => b.addEventListener('click', () => action(b.dataset.op, b.textContent.trim())));
  }
  function action(op, label) {
    const dlg = $('oba-dlg'), n = A.sel.size;
    const help = { force: 'La próxima vez que entren van a ver la bienvenida y los pasos completos.', missing: 'Solo les vamos a pedir los datos obligatorios que les faltan.', require_2fa: 'No van a poder seguir usando la plataforma hasta activar la verificación en dos pasos.', reset: 'Vuelve a empezar de cero (la verificación en dos pasos que ya tengan sigue activa).', remind: 'Les llega un aviso en la campana.', release: 'Se marca como completado sin pedirles nada.' }[op];
    dlg.innerHTML = `<div class="oba-dlg-in"><h3>${esc(label)}</h3><p>${n} persona${n > 1 ? 's' : ''}. ${help}</p>${op !== 'release' ? '<label class="ob-f"><span>Mensaje para ellas <small>opcional</small></span><input class="ob-in" id="oba-r" maxlength="300" placeholder="Ej.: Necesitamos tu foto y tu cargo para el directorio"></label>' : ''}
      <div class="oba-dlg-a"><button type="button" class="ob-btn ghost" data-x>Cancelar</button><button type="button" class="ob-btn primary" id="oba-go">Confirmar</button></div></div>`;
    dlg.showModal(); dlg.querySelector('[data-x]').addEventListener('click', () => dlg.close());
    $('oba-go').addEventListener('click', async () => { try { const r = await api('admin_action', { op, ids: [...A.sel], reason: $('oba-r')?.value || '' }); dlg.close(); toast(`Listo: ${r.n} persona${r.n === 1 ? '' : 's'}`); A.sel.clear(); await load(); } catch (e) { toast(e.message, true); } });
  }

  function drawCfg() {
    const c = JSON.parse(JSON.stringify(A.d.cfg)), F = A.d.fields, pol = A.d.policy;
    const fieldRow = f => `<tr><td>${esc(F[f])}</td><td><select class="ob-in sm" data-field="${f}">${(f === 'username' ? [['show', 'Se muestra (no se edita)'], ['hidden', 'Oculto']] : [['required', 'Obligatorio'], ['optional', 'Opcional'], ['hidden', 'No se pide']]).map(([k, l]) => `<option value="${k}"${c.fields[f] === k ? ' selected' : ''}>${l}</option>`).join('')}</select></td></tr>`;
    const tog = (path, label, sub, val) => `<label class="ob-tog"><span class="tx"><b>${label}</b>${sub ? `<small>${sub}</small>` : ''}</span><input type="checkbox" data-path="${path}"${val ? ' checked' : ''}><span class="sw"></span></label>`;
    $('oba-main').innerHTML = `<div class="oba-cfg">
      <section class="ob-card"><h3>General</h3>
        ${tog('enabled', 'Bienvenida activa', 'Las personas nuevas ven la bienvenida y el asistente al entrar por primera vez.', c.enabled)}
        <label class="ob-f"><span>Personas que ya tenían usuario</span><select class="ob-in" data-path="existing"><option value="skip"${c.existing === 'skip' ? ' selected' : ''}>No mostrarles nada (se pueden pedir a mano desde Personas)</option><option value="missing"${c.existing === 'missing' ? ' selected' : ''}>Mostrarles los pasos solo si les falta algo obligatorio</option></select></label></section>
      <section class="ob-card"><h3>Pasos</h3>
        ${tog('steps.profile', 'Perfil obligatorio', 'No se puede terminar sin los datos obligatorios.', c.steps.profile)}
        ${tog('steps.prefs', 'Preferencias obligatorias', 'Si no, se pueden saltear y quedan las predeterminadas.', c.steps.prefs)}
        <label class="ob-f"><span>Verificación en dos pasos</span><select class="ob-in" data-path="steps.security"><option value="policy"${c.steps.security === 'policy' ? ' selected' : ''}>Según la política de seguridad del sitio (hoy: ${esc(pol.scopes[pol.scope])})</option><option value="required"${c.steps.security === 'required' ? ' selected' : ''}>Obligatoria para terminar la bienvenida</option><option value="optional"${c.steps.security === 'optional' ? ' selected' : ''}>Recomendada (se puede dejar para después si la política lo permite)</option></select>
          <em class="ob-help">La política de todo el sitio se cambia en Mi perfil › Seguridad (superadmin). Para exigírsela a alguien en particular usá "Exigir 2FA" en Personas.</em></label>
        ${tog('allow_skip_security', 'Permitir "Más tarde"', 'Solo cuando la verificación no es obligatoria para esa persona.', c.allow_skip_security)}</section>
      <section class="ob-card"><h3>Datos del perfil</h3><table class="oba-ftable"><tbody>${Object.keys(F).map(fieldRow).join('')}</tbody></table></section>
      <section class="ob-card"><h3>Preferencias predeterminadas</h3>
        <label class="ob-f"><span>Tema</span><select class="ob-in" data-path="defaults.theme">${[['auto', 'Automático']].concat((A.d.themes || [{ k: "dark", label: "Oscuro" }, { k: "light", label: "Claro" }]).map(t => [t.k, t.label])).map(([k, l]) => `<option value="${k}"${c.defaults.theme === k ? ' selected' : ''}>${l}</option>`).join('')}</select></label>
        <label class="ob-f"><span>Idioma</span><select class="ob-in" data-path="defaults.language">${Object.entries(A.d.langs).map(([k, l]) => `<option value="${k}"${c.defaults.language === k ? ' selected' : ''}>${l}</option>`).join('')}</select></label>
        ${tog('defaults.notif_app', 'Avisos en la campana', '', c.defaults.notif_app)}${tog('defaults.notif_email', 'Avisos por email', '', c.defaults.notif_email)}${tog('defaults.notif_desktop', 'Avisos del navegador', '', c.defaults.notif_desktop)}${tog('defaults.notif_sound', 'Sonido', '', c.defaults.notif_sound)}
        ${tog('defaults.privacy_online', 'Mostrar en línea', '', c.defaults.privacy_online)}${tog('defaults.privacy_read_receipts', 'Confirmaciones de lectura', '', c.defaults.privacy_read_receipts)}${tog('defaults.chat_enter_send', 'Enter envía', '', c.defaults.chat_enter_send)}${tog('defaults.dashboard_animations', 'Animaciones', '', c.defaults.dashboard_animations !== false)}${tog('defaults.motion_force', 'Animaciones aunque el sistema las apague', '', !!c.defaults.motion_force)}${tog('defaults.ui_sounds', 'Sonidos de la interfaz', '', !!c.defaults.ui_sounds)}</section>
      <section class="ob-card"><h3>Textos</h3><p class="ob-help">{plataforma} se reemplaza por el nombre del sitio.</p>
        ${[['welcome_title', 'Título de bienvenida'], ['welcome_text', 'Texto de bienvenida', 1], ['welcome_cta', 'Botón para empezar'], ['final_title', 'Título final'], ['final_text', 'Texto final', 1], ['final_cta', 'Botón final']].map(([k, l, big]) => `<label class="ob-f"><span>${l}</span>${big ? `<textarea class="ob-in" rows="2" data-path="texts.${k}" maxlength="400">${esc(c.texts[k])}</textarea>` : `<input class="ob-in" data-path="texts.${k}" value="${esc(c.texts[k])}" maxlength="120">`}</label>`).join('')}</section>
      <section class="ob-card"><h3>Colores</h3><div class="oba-colors"><label>Principal <input type="color" data-path="colors.accent" value="${esc(c.colors.accent || '#ff6600')}"></label><label>Secundario <input type="color" data-path="colors.accent2" value="${esc(c.colors.accent2 || '#8b5cf6')}"></label><button type="button" class="ob-link" id="oba-colx">Usar los del tema</button></div></section>
      <div class="oba-save"><button type="button" class="ob-btn primary" id="oba-save"><i class="fa-solid fa-check"></i> Guardar</button></div></div>`;
    const set = (path, v) => { const p = path.split('.'); let o = c; for (let i = 0; i < p.length - 1; i++) o = o[p[i]]; o[p[p.length - 1]] = v; };
    document.querySelectorAll('[data-path]').forEach(x => x.addEventListener(x.type === 'checkbox' || x.tagName === 'SELECT' ? 'change' : 'input', () => set(x.dataset.path, x.type === 'checkbox' ? x.checked : x.value)));
    document.querySelectorAll('[data-field]').forEach(x => x.addEventListener('change', () => { c.fields[x.dataset.field] = x.value; }));
    $('oba-colx').addEventListener('click', () => { c.colors.accent = ''; c.colors.accent2 = ''; toast('Se van a usar los colores del tema al guardar'); });
    $('oba-save').addEventListener('click', async () => { try { const r = await api('admin_cfg', { cfg: c }); A.d.cfg = r.cfg; toast('Configuración guardada'); } catch (e) { toast(e.message, true); } });
  }
})();
