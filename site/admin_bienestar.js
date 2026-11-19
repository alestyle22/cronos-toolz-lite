/* admin_bienestar.js — Admin › Bienestar (admin_bienestar.php). Usa esc() y admToast() del panel. Habla con bienestar/api.php (adm_*).
   Pestañas: Resumen (agregado), Privacidad y reglas, Reconocimientos (categorías + moderación), Hábitos sugeridos, Vacaciones, Auditoría. */
'use strict';
const BW = { s: null, tab: 'resumen', year: new Date().getFullYear(), kudos: [], kMore: false };
async function bwApi(action, data = {}) {
  let d;
  try {
    const r = await fetch('/toolz/bienestar/api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-BN': '1' }, credentials: 'same-origin', body: JSON.stringify({ ...data, action }) });
    d = await r.json();
  } catch (e) { d = { error: 'No se pudo conectar con el servidor.' }; }
  if (d.error) { admToast(d.error, true); throw new Error(d.error); }
  return d;
}
const bwBox = () => document.getElementById('bw-body');
const bwCard = (title, body) => `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title">${title}</div></div><div class="adm-card-body">${body}</div></div>`;
const bwPct = v => v == null ? '—' : v + '%';
const BW_ACTIONS = { settings: 'Cambió ajustes', challenge_save: 'Guardó un reto', challenge_published: 'Publicó un reto', challenge_archived: 'Archivó un reto', challenge_delete: 'Borró un reto', challenge_draft: 'Pasó un reto a borrador', challenge_image: 'Subió una imagen de reto',
  survey_save: 'Guardó una encuesta', survey_published: 'Publicó una encuesta', survey_closed: 'Cerró una encuesta', survey_delete: 'Borró una encuesta', program_save: 'Guardó un programa', program_delete: 'Borró un programa',
  habit_tpl_save: 'Guardó un hábito sugerido', habit_tpl_delete: 'Borró un hábito sugerido', leave_approve: 'Aprobó una ausencia', leave_reject: 'Rechazó una ausencia', leave_balance: 'Cambió días de vacaciones', kudos_delete: 'Moderó un reconocimiento' };

function bwRender() {
  document.querySelectorAll('.bw-tab').forEach(t => t.classList.toggle('on', t.dataset.tab === BW.tab));
  const s = BW.s, st = s.settings;
  if (BW.tab === 'resumen') {
    const P = s.participation, m = s.mood, c = s.counts;
    bwBox().innerHTML = `<div class="bw-grid">${bwCard('Participación (últimos 7 días)', P.size == null ? `<p class="bw-help">Hay menos de ${P.min} personas activas: no se muestran porcentajes.</p>` :
      `<div class="bw-kpis"><div class="bw-kpi"><b>${bwPct(P.mood)}</b><small>contó su ánimo</small></div><div class="bw-kpi"><b>${bwPct(P.habits)}</b><small>cumplió un hábito</small></div><div class="bw-kpi"><b>${bwPct(P.challenges)}</b><small>sumó a un reto</small></div><div class="bw-kpi"><b>${bwPct(P.load)}</b><small>marcó su carga</small></div><div class="bw-kpi"><b>${bwPct(P.kudos)}</b><small>reconoció a alguien</small></div></div><p class="bw-help" style="margin-top:10px">Sobre ${P.size} personas activas. "—" significa menos de ${P.min} personas: no se muestra para que nadie quede expuesto.</p>`)}
      ${bwCard('Ánimo de la organización (30 días)', m.ok ? `<div class="bw-kpis"><div class="bw-kpi"><b>${String(m.avg).replace('.', ',')}</b><small>promedio de 1 a 5</small></div><div class="bw-kpi"><b>${m.prev_avg == null ? '—' : String(m.prev_avg).replace('.', ',')}</b><small>período anterior</small></div><div class="bw-kpi"><b>${m.dist[4] + m.dist[5]}%</b><small>Bien o Excelente</small></div><div class="bw-kpi"><b>${m.dist[1] + m.dist[2]}%</b><small>Mal o Muy mal</small></div></div>` : `<p class="bw-help">Todavía pocas respuestas (se muestra con ${m.min} personas o más).</p>`)}
      ${bwCard('Activo ahora', `<div class="bw-kpis"><div class="bw-kpi"><b>${c.challenges}</b><small>retos publicados</small></div><div class="bw-kpi"><b>${c.surveys}</b><small>encuestas abiertas</small></div><div class="bw-kpi"><b>${c.programs}</b><small>programas activos</small></div><div class="bw-kpi"><b>${c.leave_pending}</b><small>pedidos de ausencia en espera</small></div></div><p class="bw-help" style="margin-top:10px"><a href="/toolz/bienestar/#gestion" style="color:var(--g7-accent)">Ir a Bienestar › Gestión</a> para armar retos, encuestas y programas, y ver estadísticas por equipo.</p>`)}</div>`;
  } else if (BW.tab === 'reglas') {
    bwBox().innerHTML = `<form id="bw-rules">${bwCard('Privacidad', `<div class="bw-f"><label for="bw-min">Mínimo de personas detrás de cada número</label><input type="number" id="bw-min" name="min_group" min="3" max="50" value="${st.min_group}"><p class="bw-help">Ánimo, carga, encuestas anónimas y participación se muestran solo si hay al menos esta cantidad de personas distintas. Nunca menos de 3. Ningún admin puede ver respuestas individuales.</p></div>
      <label class="bw-chk"><input type="checkbox" name="show_org_mood" ${st.show_org_mood ? 'checked' : ''}> Mostrar a todos el ánimo agregado de la organización</label>
      <label class="bw-chk"><input type="checkbox" name="rankings" ${st.rankings ? 'checked' : ''}> Permitir rankings en los retos (quien crea el reto decide, y cada persona elige si aparece)</label>`)}
      ${bwCard('Avisos por defecto', `<p class="bw-help" style="margin-bottom:8px">Qué avisos de Bienestar le llegan a la campana a quien no eligió los suyos (cada persona los cambia en Bienestar › Avisos).</p>${Object.entries(s.notif).map(([k, l]) => `<label class="bw-chk"><input type="checkbox" name="nd_${k}" ${st.notif_defaults[k] ? 'checked' : ''}> ${esc(l)}</label>`).join('')}`)}
      <div class="bw-sticky"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Guardar</button></div></form>`;
  } else if (BW.tab === 'kudos') {
    bwBox().innerHTML = `<form id="bw-rules">${bwCard('Categorías que se ofrecen en Bienestar', `<p class="bw-help" style="margin-bottom:10px">Son los mismos reconocimientos de Comunidad. Elige cuáles aparecen al reconocer desde Bienestar (al menos dos).</p><div class="bw-cats">${Object.entries(s.all_kudos).map(([k, c]) => `<label><input type="checkbox" name="kc" value="${esc(k)}" ${st.kudos_cats.includes(k) ? 'checked' : ''}> ${esc(c.emoji)} ${esc(c.label)}</label>`).join('')}</div><div class="bw-sticky"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Guardar</button></div>`)}</form>
      ${bwCard('Moderación del muro', '<div id="bw-kudos">Cargando…</div>')}`;
    bwLoadKudos(true);
  } else if (BW.tab === 'habitos') {
    bwBox().innerHTML = bwCard('Hábitos sugeridos', `<p class="bw-help" style="margin-bottom:8px">Aparecen en Bienestar › Hábitos para que cada persona los sume con un toque. Los que ya eligieron quedan aunque borres la sugerencia.</p>
      <div>${s.tpls.map(t => `<div class="bw-tpl"><i class="ic fa-solid ${esc(t.icon)}" style="background:${t.color}22;color:${t.color}"></i><div class="t"><b>${esc(t.title)}</b>${t.active ? '' : ' <small style="display:inline">(oculto)</small>'}<small>${esc(t.hint)} · ${t.freq === 'weekly' ? t.target + ' veces por semana' : 'todos los días'}</small></div><button type="button" class="btn btn-ghost btn-sm" data-bw="tpl-edit" data-id="${t.id}">Editar</button><button type="button" class="btn btn-ghost btn-sm" data-bw="tpl-del" data-id="${t.id}"><i class="fa-regular fa-trash-can"></i></button></div>`).join('') || '<p class="bw-help">No hay sugerencias.</p>'}</div>
      <form id="bw-tpl" style="margin-top:16px;display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));align-items:end"><input type="hidden" name="id" value="">
        <div class="bw-f" style="margin:0"><label>Nombre</label><input type="text" name="title" maxlength="80" required></div><div class="bw-f" style="margin:0"><label>Ayuda</label><input type="text" name="hint" maxlength="200"></div>
        <div class="bw-f" style="margin:0"><label>Frecuencia</label><select name="freq"><option value="daily">Todos los días</option><option value="weekly">Veces por semana</option></select></div><div class="bw-f" style="margin:0"><label>Veces por semana</label><input type="number" name="target" min="1" max="7" value="3"></div>
        <div class="bw-f" style="margin:0"><label>Ícono</label><select name="icon">${s.icons.map(i => `<option value="${i}">${i.replace('fa-', '')}</option>`).join('')}</select></div><div class="bw-f" style="margin:0"><label>Color</label><select name="color">${s.colors.map(c => `<option value="${c}" style="color:${c}">${c}</option>`).join('')}</select></div>
        <label class="bw-chk"><input type="checkbox" name="active" checked> Visible</label><button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> <span>Agregar</span></button></form>`);
  } else if (BW.tab === 'vacaciones') {
    bwBox().innerHTML = `<div class="bw-grid"><form id="bw-rules">${bwCard('Reglas', `<div class="bw-f"><label for="bw-vac">Días de vacaciones por año (por defecto)</label><input type="number" id="bw-vac" name="vac_days" min="0" max="60" value="${st.vac_days}"><p class="bw-help">Días hábiles. A cada persona se le puede poner otro número abajo.</p></div>
      <div class="bw-f"><span>Tipos de licencia que se pueden pedir</span>${Object.entries(s.all_leave).map(([k, t]) => `<label class="bw-chk"><input type="checkbox" name="lt" value="${k}" ${st.leave_types.includes(k) ? 'checked' : ''} ${k === 'vacaciones' ? 'disabled' : ''}> <i class="fa-solid ${t.icon}" style="color:${t.color}"></i> ${esc(t.label)}</label>`).join('')}<p class="bw-help">Sin información médica: Bienestar no pide ni guarda diagnósticos ni certificados.</p></div>
      <div class="bw-sticky"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Guardar</button></div>`)}</form>
      ${bwCard('Días por persona', `<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px"><label class="bw-help" for="bw-year">Año</label><select id="bw-year">${[BW.year - 1, BW.year, BW.year + 1].map(y => `<option ${y === BW.year ? 'selected' : ''}>${y}</option>`).join('')}</select></div><div id="bw-bal">Cargando…</div>`)}</div>`;
    bwLoadBal();
  } else if (BW.tab === 'auditoria') {
    bwBox().innerHTML = bwCard('Auditoría de Bienestar', '<div id="bw-aud">Cargando…</div>');
    bwApi('adm_audit').then(d => {
      document.getElementById('bw-aud').innerHTML = d.items.length ? `<table class="bw-table"><thead><tr><th>Cuándo</th><th>Quién</th><th>Qué</th><th>Detalle</th></tr></thead><tbody>${d.items.map(a => `<tr><td>${esc(a.at)}</td><td>${esc(a.by)}</td><td>${esc(BW_ACTIONS[a.action] || a.action)}</td><td><small>${esc(a.target ? '#' + a.target + ' ' : '')}${esc(a.detail ? JSON.stringify(a.detail).slice(0, 160) : '')}</small></td></tr>`).join('')}</tbody></table>` : '<p class="bw-help">Todavía no hay operaciones registradas.</p>';
    }).catch(() => {});
  }
}
async function bwLoadKudos(reset) {
  if (reset) BW.kudos = [];
  const d = await bwApi('adm_kudos', { before: BW.kudos.length ? BW.kudos[BW.kudos.length - 1].id : 0 });
  BW.kudos = BW.kudos.concat(d.items); BW.kMore = d.more;
  const box = document.getElementById('bw-kudos'); if (!box) return;
  box.innerHTML = BW.kudos.length ? BW.kudos.map(k => `<div class="bw-kudo"><span style="font-size:18px">${esc(k.cat.emoji)}</span><div class="t"><b>${esc(k.from.name)}</b> → <b>${esc(k.to ? k.to.name : '')}</b> · ${esc(k.cat.label)}<br>${esc(k.body)}<br><small>${esc(k.at)} · ${k.reactions.reduce((a, r) => a + +r.cnt, 0)} reacciones · ${k.replies.length} comentarios</small></div><button type="button" class="btn btn-ghost btn-sm" data-bw="kudo-del" data-id="${k.id}"><i class="fa-regular fa-trash-can"></i> Quitar</button></div>`).join('') + (BW.kMore ? '<button type="button" class="btn btn-ghost btn-sm" data-bw="kudo-more" style="margin-top:10px">Ver más</button>' : '')
    : '<p class="bw-help">Todavía no hay reconocimientos públicos.</p>';
}
async function bwLoadBal() {
  const d = await bwApi('adm_balances', { year: BW.year }), box = document.getElementById('bw-bal'); if (!box) return;
  box.innerHTML = `<table class="bw-table"><thead><tr><th>Persona</th><th>Días del año</th><th>Usados</th><th>En espera</th><th>Disponibles</th></tr></thead><tbody>${d.items.map(u => `<tr><td>${esc(u.name)}</td><td><input type="number" min="0" max="365" value="${u.total}" data-bw-bal="${u.id}" aria-label="Días de ${esc(u.name)}" placeholder="${d.default}">${u.custom ? ` <button type="button" class="btn btn-ghost btn-sm" data-bw="bal-reset" data-id="${u.id}" title="Volver al valor por defecto">↺</button>` : ''}</td><td>${u.used}</td><td>${u.pending}</td><td><b>${u.available}</b></td></tr>`).join('')}</tbody></table><p class="bw-help" style="margin-top:8px">Cambia el número y sal del campo para guardarlo. Queda en la auditoría.</p>`;
}
function bwCollect(f) {
  const st = { ...BW.s.settings };
  if (f.min_group) st.min_group = +f.min_group.value;
  if (f.show_org_mood) st.show_org_mood = f.show_org_mood.checked;
  if (f.rankings) st.rankings = f.rankings.checked;
  if (f.vac_days) st.vac_days = +f.vac_days.value;
  if (f.querySelector('[name=kc]')) st.kudos_cats = [...f.querySelectorAll('[name=kc]:checked')].map(i => i.value);
  if (f.querySelector('[name=lt]')) st.leave_types = [...f.querySelectorAll('[name=lt]:checked')].map(i => i.value);
  if (f.querySelector('[name^=nd_]')) { st.notif_defaults = {}; Object.keys(BW.s.notif).forEach(k => { st.notif_defaults[k] = f['nd_' + k].checked; }); }
  return st;
}
async function bwLoad() { BW.s = await bwApi('adm_get'); bwRender(); }
(function () {
  const sec = document.getElementById('sec-bienestar'); if (!sec) return;
  sec.addEventListener('click', async ev => {
    const t = ev.target.closest('.bw-tab'); if (t) { BW.tab = t.dataset.tab; bwRender(); return; }
    const b = ev.target.closest('[data-bw]'); if (!b) return;
    try {
      if (b.dataset.bw === 'tpl-edit') {
        const x = BW.s.tpls.find(t2 => t2.id === +b.dataset.id), f = document.getElementById('bw-tpl');
        f.id.value = x.id; f.title.value = x.title; f.hint.value = x.hint; f.freq.value = x.freq; f.target.value = x.target; f.icon.value = x.icon; f.color.value = x.color; f.active.checked = x.active;
        f.querySelector('[type=submit] span').textContent = 'Guardar cambios'; f.title.focus();
      } else if (b.dataset.bw === 'tpl-del') {
        if (!confirm('¿Borrar esta sugerencia? Los hábitos que ya armó cada persona quedan.')) return;
        BW.s.tpls = (await bwApi('adm_tpl_delete', { id: +b.dataset.id })).tpls; bwRender(); admToast('Sugerencia borrada');
      } else if (b.dataset.bw === 'kudo-del') {
        if (!confirm('¿Quitar este reconocimiento del muro? Se borra también de Comunidad y queda en la auditoría.')) return;
        await bwApi('kudos_delete', { id: +b.dataset.id }); BW.kudos = BW.kudos.filter(k => k.id !== +b.dataset.id); b.closest('.bw-kudo').remove(); admToast('Reconocimiento quitado');
      } else if (b.dataset.bw === 'kudo-more') { b.disabled = true; await bwLoadKudos(false); }
      else if (b.dataset.bw === 'bal-reset') { await bwApi('adm_balance_set', { user_id: +b.dataset.id, year: BW.year, days: null }); bwLoadBal(); admToast('Volvió al valor por defecto'); }
    } catch (e) {}
  });
  sec.addEventListener('change', async ev => {
    if (ev.target.id === 'bw-year') { BW.year = +ev.target.value; bwLoadBal(); }
    else if (ev.target.dataset.bwBal) {
      const v = ev.target.value.trim();
      try { await bwApi('adm_balance_set', { user_id: +ev.target.dataset.bwBal, year: BW.year, days: v === '' ? null : +v }); admToast('Días guardados ✓'); bwLoadBal(); } catch (e) {}
    }
  });
  sec.addEventListener('submit', async ev => {
    ev.preventDefault();
    const f = ev.target, btn = f.querySelector('[type=submit]'); if (btn) btn.disabled = true;
    try {
      // getAttribute: en #bw-tpl hay un campo llamado "id" y f.id devolvería ese campo, no el id del formulario
      const fid = f.getAttribute('id');
      if (fid === 'bw-rules') { BW.s = await bwApi('adm_save', { settings: bwCollect(f) }); admToast('Ajustes guardados ✓'); bwRender(); }
      else if (fid === 'bw-tpl') {
        BW.s.tpls = (await bwApi('adm_tpl_save', { id: +f.id.value || 0, title: f.title.value, hint: f.hint.value, freq: f.freq.value, target: +f.target.value, icon: f.icon.value, color: f.color.value, active: f.active.checked })).tpls;
        admToast('Sugerencia guardada ✓'); bwRender();
      }
    } catch (e) {}
    if (btn) btn.disabled = false;
  });
})();
if (typeof SECTION_LOADERS !== 'undefined') SECTION_LOADERS.bienestar = () => { bwLoad().catch(() => {}); };
