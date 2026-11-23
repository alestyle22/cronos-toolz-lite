/* social/reports.js — Social Studio · Report Maker (#informes, #informes/<id>). It uses the helpers of app.js ($, esc, api, toast, S…).
   History, new report, editor (sections, texts, automatic summary, data date), preview (report.php), server PDF,
   online link, sending by email, templates and automatic monthly reports. No inline code (CSP). */
'use strict';
const RP = { meta: null, tab: 'list', cur: null, dirty: false };
const RP_ST = { draft: ['Borrador', '#94a3b8'], final: ['Listo', '#3b82f6'], sent: ['Enviado', '#22c55e'] };
const rpGroups = () => S.meta.group === 'client' ? S.meta.clients : S.meta.brands;
const rpScopeName = r => r.scope === 'all' || !r.scope_id ? 'Todas las cuentas' : ((r.scope === 'client' ? S.meta.clients : S.meta.brands).find(g => g.id === r.scope_id) || { name: '#' + r.scope_id }).name;
const rpD = s => s ? s.split('-').reverse().join('/') : '';

async function openReports() {
  const box = $('#ss-reports');
  if (!RP.meta) { box.innerHTML = '<div class="ss-skel" style="grid-template-columns:1fr"><span style="height:220px"></span></div>'; try { RP.meta = await api('rep_meta'); } catch (e) { box.innerHTML = `<div class="ss-empty"><p>${esc(e.message)}</p></div>`; return; } }
  const m = location.hash.match(/^#informes\/(\d+)/);
  if (m) rpOpen(Number(m[1])); else { RP.cur = null; rpDraw(); }
}
function rpTabs() {
  return `<div class="rp-top"><div class="an-presets">${[['list', 'Informes'], ['tpl', 'Plantillas'], ['auto', 'Automáticos']].map(([k, l]) => `<button type="button" data-rp-tab="${k}"${RP.tab === k ? ' class="on"' : ''}>${l}</button>`).join('')}</div>
    <span class="sp"></span><button type="button" class="ss-btn" data-act="rp-new"><i class="fa-solid fa-plus"></i> Nuevo informe</button></div>`;
}
function rpDraw() {
  const box = $('#ss-reports'), M = RP.meta;
  if (RP.tab === 'tpl') { box.innerHTML = rpTabs() + rpTplView(); return; }
  if (RP.tab === 'auto') { box.innerHTML = rpTabs() + rpAutoView(); return; }
  box.innerHTML = rpTabs() + (M.reports.length ? `<div class="rp-list">${M.reports.map(r => { const st = RP_ST[r.status] || RP_ST.draft;
    return `<article class="rp-item"><div class="ic"><i class="fa-solid fa-file-lines"></i></div><div class="x"><b>${esc(r.title)}</b><small>${esc(rpScopeName(r))} · ${rpD(r.from)} – ${rpD(r.to)}${r.auto ? ' · automático' : ''}${r.sent_at ? ' · enviado a ' + esc(r.sent_to) : ''}</small>
      <small>Armado ${esc(fmtWhen(r.built_at))}${r.link ? ' · <i class="fa-solid fa-link"></i> enlace activo' : ''}</small></div>
      <span class="ss-pill" style="--c:${st[1]}">${st[0]}</span>
      <div class="row"><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-rp-open="${r.id}"><i class="fa-solid fa-pen"></i> Editar</button><a class="ss-icon" href="report.php?id=${r.id}" target="_blank" rel="noopener" title="Vista previa" aria-label="Vista previa"><i class="fa-regular fa-eye"></i></a>
        <a class="ss-icon" href="report.php?id=${r.id}&amp;pdf=1&amp;dl=1" title="Descargar PDF" aria-label="Descargar PDF"><i class="fa-regular fa-file-pdf"></i></a><button type="button" class="ss-icon" data-rp-dup="${r.id}" title="Duplicar" aria-label="Duplicar"><i class="fa-regular fa-copy"></i></button>
        <button type="button" class="ss-icon" data-rp-del="${r.id}" title="Borrar" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button></div></article>`; }).join('')}</div>`
    : `<div class="ss-card"><div class="ss-empty"><div class="art"><i class="fa-solid fa-file-lines"></i></div><h3>Todavía no hay informes</h3><p>Armá el informe de un cliente con los datos de Analytics: portada con tu marca y la suya, resumen, métricas, gráficos, publicaciones destacadas, conclusiones y recomendaciones. Se ve en línea, se descarga en PDF y se manda por mail.</p><button type="button" class="ss-btn" data-act="rp-new"><i class="fa-solid fa-plus"></i> Nuevo informe</button></div></div>`);
}
function rpScopeSelect(id, scope, sid) {
  const word = S.meta.group === 'client' ? 'client' : 'brand';
  return `<select class="ss-in" id="${id}"><option value="all:0"${scope === 'all' ? ' selected' : ''}>Todas las cuentas</option>${rpGroups().map(g => `<option value="${word}:${g.id}"${scope === word && sid === g.id ? ' selected' : ''}>${esc(g.name)}</option>`).join('')}</select>`;
}
function rpNew() {
  const M = RP.meta, t = new Date(), pm = [ymd(new Date(t.getFullYear(), t.getMonth() - 1, 1)), ymd(new Date(t.getFullYear(), t.getMonth(), 0))];
  openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-file-lines"></i> Nuevo informe</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b"><div class="ss-form">
      <label class="ss-f"><span>${S.meta.group === 'client' ? 'Cliente' : 'Marca'}</span>${rpScopeSelect('rpn-scope', 'all', 0)}</label>
      <label class="ss-f"><span>Plantilla</span><select class="ss-in" id="rpn-tpl">${M.templates.map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}</select></label>
      <label class="ss-f"><span>Desde</span><input type="date" class="ss-in" id="rpn-from" value="${pm[0]}"></label>
      <label class="ss-f"><span>Hasta</span><input type="date" class="ss-in" id="rpn-to" value="${pm[1]}" max="${ymd(t)}"></label>
      <label class="ss-f"><span>Comparar con</span><select class="ss-in" id="rpn-cmp"><option value="prev">El período anterior</option><option value="year">El mismo período del año pasado</option><option value="none">Sin comparar</option></select></label>
      <label class="ss-f"><span>Título<small>opcional</small></span><input class="ss-in" id="rpn-title" maxlength="160" placeholder="Informe de redes · noviembre"></label></div>
      <p class="ss-hint">Usa los datos ya sincronizados en Analytics y los congela en el informe (con su fecha). Después se pueden actualizar.</p></div>
    <div class="ss-dg-f"><span class="sp"></span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button><button type="button" class="ss-btn" data-act="rp-create"><i class="fa-solid fa-check"></i> Armar informe</button></div>`, 'sm');
}
async function rpOpen(id) {
  RP.cur = null; $('#ss-reports').innerHTML = '<div class="ss-skel" style="grid-template-columns:1fr"><span style="height:300px"></span></div>';
  try { RP.cur = (await api('rep_get', { id })).report; } catch (e) { history.replaceState(null, '', '#informes'); RP.tab = 'list'; rpDraw(); return; }
  if (location.hash !== '#informes/' + id) history.replaceState(null, '', '#informes/' + id);
  RP.dirty = false; rpEditor();
}
function rpEditor() {
  const r = RP.cur, M = RP.meta, sec = r.sections.list || [], st = RP_ST[r.status] || RP_ST.draft;
  $('#ss-reports').innerHTML = `<div class="rp-top"><button type="button" class="ss-link" data-act="rp-back"><i class="fa-solid fa-arrow-left"></i> Informes</button><span class="sp"></span>
      <a class="ss-btn ss-btn-ghost ss-btn-sm" href="report.php?id=${r.id}" target="_blank" rel="noopener"><i class="fa-regular fa-eye"></i> Vista previa</a>
      <a class="ss-btn ss-btn-ghost ss-btn-sm" href="report.php?id=${r.id}&amp;pdf=1&amp;dl=1"><i class="fa-regular fa-file-pdf"></i> PDF</a>
      <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="rp-link"><i class="fa-solid fa-link"></i> Enlace</button>
      <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="rp-send"><i class="fa-regular fa-paper-plane"></i> Enviar</button>
      <button type="button" class="ss-btn ss-btn-sm" data-act="rp-save"><i class="fa-solid fa-check"></i> Guardar</button></div>
    <div class="rp-ed">
      <section class="ss-card"><div class="ss-form">
        <label class="ss-f full"><span>Título</span><input class="ss-in" id="rpe-title" maxlength="160" value="${esc(r.title)}"></label>
        <div class="ss-f full"><span>Datos</span><p class="rp-data">${esc(rpScopeName(r))} · ${rpD(r.from)} – ${rpD(r.to)} · armado ${esc(fmtWhen(r.built_at))}${r.last_sync ? ' · datos de Meta del ' + esc(fmtWhen(r.last_sync)) : ' · <b>sin datos sincronizados</b>'}
          <button type="button" class="ss-link" data-act="rp-refresh"><i class="fa-solid fa-rotate"></i> Actualizar datos</button></p></div>
        <label class="ss-f"><span>Estado</span><select class="ss-in" id="rpe-status"${r.status === 'sent' ? ' disabled' : ''}><option value="draft"${r.status === 'draft' ? ' selected' : ''}>Borrador</option><option value="final"${r.status === 'final' ? ' selected' : ''}>Listo</option>${r.status === 'sent' ? '<option selected>Enviado</option>' : ''}</select></label>
        <label class="ss-f"><span>Gráfico por día de</span><select class="ss-in" id="rpe-metric">${Object.entries(M.metrics).map(([k, l]) => `<option value="${k}"${k === r.sections.metric ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
        <div class="ss-f full"><span>Secciones</span><div class="ss-fchecks">${Object.entries(M.sections).map(([k, l]) => `<label><input type="checkbox" data-rp-sec="${k}"${sec.includes(k) ? ' checked' : ''}> ${esc(l)}</label>`).join('')}</div></div>
      </div></section>
      <section class="ss-card"><div class="ss-sec"><h4>Resumen</h4><div class="ss-form"><label class="ss-f full"><span>Introducción<small>opcional</small></span><textarea class="ss-in" id="rpe-summary" rows="3" maxlength="6000">${esc(r.texts.summary || '')}</textarea></label></div>
        <p class="ss-hint">Se suma el resumen armado con los datos (no se inventa nada):</p><ul class="rp-auto">${(r.auto_summary || []).map(l => `<li>${esc(l)}</li>`).join('')}</ul></div>
        <div class="ss-sec"><h4>Conclusiones y recomendaciones ${M.ai ? '<button type="button" class="ss-link" data-act="rp-ai"><i class="fa-solid fa-wand-magic-sparkles"></i> Sugerir con IA (borrador)</button>' : ''}</h4><div class="ss-form">
          <label class="ss-f full"><span>Conclusiones</span><textarea class="ss-in" id="rpe-concl" rows="5" maxlength="6000">${esc(r.texts.conclusions || '')}</textarea></label>
          <label class="ss-f full"><span>Recomendaciones</span><textarea class="ss-in" id="rpe-recos" rows="5" maxlength="6000">${esc(r.texts.recommendations || '')}</textarea></label></div></div></section>
    </div>`;
  $$('#ss-reports input, #ss-reports textarea, #ss-reports select').forEach(el => el.addEventListener('input', () => { RP.dirty = true; }));
}
function rpCollect() {
  return { title: $('#rpe-title').value, status: $('#rpe-status').value, sections: { list: $$('[data-rp-sec]:checked').map(x => x.dataset.rpSec), metric: $('#rpe-metric').value },
           texts: { summary: $('#rpe-summary').value, conclusions: $('#rpe-concl').value, recommendations: $('#rpe-recos').value } };
}
async function rpSave(quiet) {
  const body = rpCollect(); if (RP.cur.status === 'sent') delete body.status;
  try { RP.cur = (await api('rep_update', { id: RP.cur.id, ...body })).report; RP.dirty = false; if (!quiet) toast('Informe guardado'); RP.meta = await api('rep_meta'); return true; } catch (e) { return false; }
}
function rpTplView() {
  const M = RP.meta;
  return `<div class="rp-list">${M.templates.map(t => `<article class="rp-item"><div class="ic"><i class="fa-solid fa-swatchbook"></i></div><div class="x"><b>${esc(t.name)}${t.is_default ? ' <small>(por defecto)</small>' : ''}</b><small>${t.sections.map(s => esc(M.sections[s] || s)).join(' · ')}</small></div>
      <div class="row"><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-rp-tpl="${t.id}"><i class="fa-solid fa-pen"></i> Editar</button><button type="button" class="ss-icon" data-rp-tdel="${t.id}" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button></div></article>`).join('')}</div>
    <button type="button" class="ss-btn ss-btn-ghost" data-rp-tpl="0" style="margin-top:10px"><i class="fa-solid fa-plus"></i> Nueva plantilla</button>`;
}
function rpTplEdit(id) {
  const M = RP.meta, t = M.templates.find(x => x.id === id) || { id: 0, name: '', sections: Object.keys(M.sections), metric: 'interactions', intro: '' };
  openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h">${t.id ? 'Editar plantilla' : 'Nueva plantilla'}</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b"><div class="ss-form"><input type="hidden" id="rpt-id" value="${t.id}">
      <label class="ss-f"><span>Nombre</span><input class="ss-in" id="rpt-name" maxlength="80" value="${esc(t.name)}"></label>
      <label class="ss-f"><span>Gráfico por día de</span><select class="ss-in" id="rpt-metric">${Object.entries(M.metrics).map(([k, l]) => `<option value="${k}"${k === t.metric ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
      <div class="ss-f full"><span>Secciones</span><div class="ss-fchecks">${Object.entries(M.sections).map(([k, l]) => `<label><input type="checkbox" data-rpt-sec="${k}"${t.sections.includes(k) ? ' checked' : ''}> ${esc(l)}</label>`).join('')}</div></div>
      <label class="ss-f full"><span>Introducción por defecto<small>opcional</small></span><textarea class="ss-in" id="rpt-intro" rows="3" maxlength="4000">${esc(t.intro)}</textarea></label></div></div>
    <div class="ss-dg-f"><span class="sp"></span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button><button type="button" class="ss-btn" data-act="rp-tsave"><i class="fa-solid fa-check"></i> Guardar</button></div>`, 'sm');
}
function rpAutoView() {
  const M = RP.meta;
  if (M.auto_ok === false) return `<div class="ss-empty"><div class="art"><i class="fa-solid fa-lock"></i></div><h3>Los informes automáticos no están en tu plan</h3><p>Para que el informe del mes se arme y se envíe solo, mejorá el plan desde Admin › Licencia.${M.scheds.length ? ' Los que ya estaban quedan guardados, pero no se arman.' : ''}</p></div>`;
  return `<p class="ss-hint">Cada mes, desde el día elegido, se arma solo el informe del mes anterior con los datos ya sincronizados (la fecha de los datos queda en el informe) y, si tiene destinatarios, se envía por mail con el PDF. Necesita el cron de Social Studio.</p>
    <div class="rp-list">${M.scheds.map(s => `<article class="rp-item"><div class="ic"><i class="fa-regular fa-calendar-check"></i></div><div class="x"><b>${esc(rpScopeName(s))}</b><small>Día ${s.day} de cada mes · ${esc((M.templates.find(t => t.id === s.template_id) || { name: 'plantilla por defecto' }).name)}${s.recipients ? ' · a ' + esc(s.recipients) : ' · sin envío (queda en el historial)'}${s.last_month ? ' · último: ' + esc(s.last_month) : ''}</small></div>
      <span class="ss-pill" style="--c:${s.active ? '#22c55e' : '#94a3b8'}">${s.active ? 'Activo' : 'Pausado'}</span><div class="row"><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-rp-sch="${s.id}"><i class="fa-solid fa-pen"></i> Editar</button><button type="button" class="ss-icon" data-rp-sdel="${s.id}" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button></div></article>`).join('') || '<div class="ss-empty sm"><p>Todavía no hay informes automáticos.</p></div>'}</div>
    <button type="button" class="ss-btn ss-btn-ghost" data-rp-sch="0" style="margin-top:10px"><i class="fa-solid fa-plus"></i> Nuevo informe automático</button>`;
}
function rpSchEdit(id) {
  const M = RP.meta, s = M.scheds.find(x => x.id === id) || { id: 0, scope: 'all', scope_id: 0, template_id: 0, recipients: '', day: 2, active: true };
  openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h">Informe automático mensual</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b"><div class="ss-form"><input type="hidden" id="rps-id" value="${s.id}">
      <label class="ss-f"><span>${S.meta.group === 'client' ? 'Cliente' : 'Marca'}</span>${rpScopeSelect('rps-scope', s.scope, s.scope_id)}</label>
      <label class="ss-f"><span>Plantilla</span><select class="ss-in" id="rps-tpl">${M.templates.map(t => `<option value="${t.id}"${t.id === s.template_id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label>
      <label class="ss-f"><span>Día del mes<small>1 a 28</small></span><input class="ss-in" id="rps-day" type="number" min="1" max="28" value="${s.day}"></label>
      <label class="ss-check" style="align-self:end"><input type="checkbox" id="rps-active"${s.active ? ' checked' : ''}> Activo</label>
      <label class="ss-f full"><span>Mandar a<small>vacío = solo queda en el historial · hasta 10, separados por coma</small></span><input class="ss-in" id="rps-to" value="${esc(s.recipients)}" placeholder="cliente@empresa.com"></label></div></div>
    <div class="ss-dg-f"><span class="sp"></span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button><button type="button" class="ss-btn" data-act="rp-ssave"><i class="fa-solid fa-check"></i> Guardar</button></div>`, 'sm');
}

document.addEventListener('click', async e => {
  const b = e.target.closest('[data-rp-tab],[data-rp-open],[data-rp-dup],[data-rp-del],[data-rp-tpl],[data-rp-tdel],[data-rp-sch],[data-rp-sdel],[data-act^="rp-"]'); if (!b) return;
  if (b.dataset.rpTab) { RP.tab = b.dataset.rpTab; RP.cur = null; history.replaceState(null, '', '#informes'); rpDraw(); return; }
  if (b.dataset.rpOpen) { rpOpen(Number(b.dataset.rpOpen)); return; }
  if (b.dataset.rpDup) { try { const d = await api('rep_duplicate', { id: Number(b.dataset.rpDup) }); RP.meta = await api('rep_meta'); toast('Copia creada con los datos de hoy'); rpOpen(d.id); } catch (err) {} return; }
  if (b.dataset.rpDel) { if (!confirm('¿Borrar este informe? Si tenía un enlace, deja de funcionar.')) return; try { await api('rep_delete', { id: Number(b.dataset.rpDel) }); RP.meta = await api('rep_meta'); rpDraw(); toast('Informe borrado'); } catch (err) {} return; }
  if (b.dataset.rpTpl !== undefined) { rpTplEdit(Number(b.dataset.rpTpl)); return; }
  if (b.dataset.rpTdel) { if (!confirm('¿Borrar esta plantilla?')) return; try { RP.meta.templates = (await api('rep_tpl_delete', { id: Number(b.dataset.rpTdel) })).templates; rpDraw(); } catch (err) {} return; }
  if (b.dataset.rpSch !== undefined) { rpSchEdit(Number(b.dataset.rpSch)); return; }
  if (b.dataset.rpSdel) { if (!confirm('¿Borrar este informe automático? Los informes ya armados quedan.')) return; try { RP.meta.scheds = (await api('rep_sched_delete', { id: Number(b.dataset.rpSdel) })).scheds; rpDraw(); } catch (err) {} return; }
  switch (b.dataset.act) {
    case 'rp-new': rpNew(); break;
    case 'rp-create': {
      const [scope, sid] = $('#rpn-scope').value.split(':'); b.disabled = true;
      try { const d = await api('rep_create', { report: { scope, scope_id: Number(sid), template_id: Number($('#rpn-tpl').value), from: $('#rpn-from').value, to: $('#rpn-to').value, compare: $('#rpn-cmp').value, title: $('#rpn-title').value } });
        closeModal(); RP.meta = await api('rep_meta'); rpOpen(d.id); toast('Informe armado'); } catch (err) { b.disabled = false; }
      break;
    }
    case 'rp-back': if (RP.dirty && !confirm('Hay cambios sin guardar. ¿Salir igual?')) return; RP.cur = null; history.replaceState(null, '', '#informes'); rpDraw(); break;
    case 'rp-save': rpSave(); break;
    case 'rp-refresh': if (RP.dirty && !(await rpSave(true))) return; try { RP.cur = (await api('rep_refresh', { id: RP.cur.id })).report; rpEditor(); toast('Datos actualizados'); } catch (err) {} break;
    case 'rp-ai': {
      if ((($('#rpe-concl').value + $('#rpe-recos').value).trim()) && !confirm('Se reemplazan las conclusiones y recomendaciones por un borrador de la IA. ¿Seguir?')) return;
      b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Pensando…';
      try { const d = await api('rep_ai', { id: RP.cur.id }); $('#rpe-concl').value = d.conclusions; $('#rpe-recos').value = d.recommendations; RP.dirty = true; toast('Borrador listo: revisalo y guardá'); } catch (err) {}
      b.disabled = false; b.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Sugerir con IA (borrador)'; break;
    }
    case 'rp-link': {
      const r = RP.cur;
      openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-link"></i> Enlace del informe</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="ss-dg-b"><p class="ss-hint" style="margin:0 0 10px">Cualquiera que tenga el enlace ve el informe (sin entrar a Cronos). Se puede cambiar o desactivar cuando quieras.</p>
        ${r.link ? `<span class="ss-copy"><input class="ss-in" readonly id="rp-linkv" value="${esc(r.link)}"><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="acc-copy" data-for="rp-linkv"><i class="fa-regular fa-copy"></i> Copiar</button></span>` : '<p>No tiene enlace activo.</p>'}</div>
        <div class="ss-dg-f">${r.link ? '<button type="button" class="ss-link danger" data-act="rp-linkoff"><i class="fa-solid fa-link-slash"></i> Desactivar</button>' : ''}<span class="sp"></span><button type="button" class="ss-btn" data-act="rp-linkon"><i class="fa-solid fa-rotate"></i> ${r.link ? 'Cambiar el enlace' : 'Crear enlace'}</button></div>`, 'sm');
      break;
    }
    case 'rp-linkon': case 'rp-linkoff': try { await api('rep_link', { id: RP.cur.id, on: b.dataset.act === 'rp-linkon' }); RP.cur = (await api('rep_get', { id: RP.cur.id })).report; closeModal(); b.dataset.act === 'rp-linkon' && $('[data-act="rp-link"]').click(); toast(b.dataset.act === 'rp-linkon' ? 'Enlace listo' : 'Enlace desactivado'); } catch (err) {} break;
    case 'rp-send':
      if (RP.dirty && !(await rpSave(true))) return;
      openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-regular fa-paper-plane"></i> Enviar el informe</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="ss-dg-b"><div class="ss-form"><label class="ss-f full"><span>Para<small>hasta 10, separados por coma</small></span><input class="ss-in" id="rps-mails" placeholder="cliente@empresa.com" value="${esc(RP.cur.sent_to || '')}"></label>
        <label class="ss-f full"><span>Mensaje<small>opcional</small></span><textarea class="ss-in" id="rps-msg" rows="4" maxlength="2000" placeholder="Hola, te dejamos el informe del mes…"></textarea></label></div>
        <p class="ss-hint">Sale con la plantilla de mails del sitio, con el botón al informe en línea (se crea el enlace si no tenía) y el PDF adjunto.</p></div>
        <div class="ss-dg-f"><span class="sp"></span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button><button type="button" class="ss-btn" data-act="rp-sendgo"><i class="fa-regular fa-paper-plane"></i> Enviar</button></div>`, 'sm');
      break;
    case 'rp-sendgo': b.disabled = true;
      try { const d = await api('rep_send', { id: RP.cur.id, to: $('#rps-mails').value, message: $('#rps-msg').value }); closeModal(); RP.cur = d.report; RP.meta = await api('rep_meta'); rpEditor(); toast(`Enviado a ${d.sent} ${d.sent === 1 ? 'persona' : 'personas'}`); } catch (err) { b.disabled = false; }
      break;
    case 'rp-tsave': try { RP.meta.templates = (await api('rep_tpl_save', { tpl: { id: Number($('#rpt-id').value), name: $('#rpt-name').value, metric: $('#rpt-metric').value, intro: $('#rpt-intro').value, sections: $$('[data-rpt-sec]:checked').map(x => x.dataset.rptSec) } })).templates; closeModal(); rpDraw(); toast('Plantilla guardada'); } catch (err) {} break;
    case 'rp-ssave': {
      const [scope, sid] = $('#rps-scope').value.split(':');
      try { RP.meta.scheds = (await api('rep_sched_save', { sched: { id: Number($('#rps-id').value), scope, scope_id: Number(sid), template_id: Number($('#rps-tpl').value), day: Number($('#rps-day').value), active: $('#rps-active').checked, recipients: $('#rps-to').value } })).scheds; closeModal(); rpDraw(); toast('Guardado'); } catch (err) {}
      break;
    }
  }
});
