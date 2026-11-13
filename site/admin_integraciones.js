/* admin_integraciones.js — Admin › Integraciones (admin_integraciones.php). Usa esc() y admToast() del panel. Habla con integraciones/api.php.
   Las claves nunca llegan acá: un campo secreto viene como {saved: true|false} y se muestra "guardada". */
'use strict';
const IN = { s: null, loaded: false, msg: {}, logFilter: '' };
async function inApi(action, data = {}) {
  let d;
  try { const r = await fetch('/toolz/integraciones/api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-INT': '1' }, credentials: 'same-origin', body: JSON.stringify({ ...data, action }) }); d = await r.json(); }
  catch (e) { d = { error: 'No se pudo conectar con el servidor.' }; }
  if (d.error) { admToast(d.error, true); throw new Error(d.error); }
  return d;
}
const inDate = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + ' ' + s.slice(11, 16) : '';
function inField(id, k, f, v) {
  const name = `name="${k}"`, req = f.required ? ' <span style="color:#f87171">*</span>' : '';
  const help = f.help ? `<small class="in-help">${esc(f.help)}</small>` : '';
  if (f.type === 'bool') return `<label class="in-ck full"><input type="checkbox" ${name} ${v ? 'checked' : ''}> ${esc(f.label)}</label>`;
  if (f.type === 'select') return `<label class="adm-label">${esc(f.label)}${req}<select class="adm-input" ${name}>${Object.entries(f.options).map(([o, l]) => `<option value="${esc(o)}" ${String(v) === o ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>${help}</label>`;
  if (f.type === 'secret' || f.type === 'pem') {
    const saved = v && v.saved;
    const head = `${esc(f.label)}${req}${saved ? `<span class="in-saved"><i class="fa-solid fa-lock"></i> guardada</span><button type="button" class="in-clear" data-clear="${k}">borrar</button>` : ''}`;
    const ph = saved ? 'Guardada. Escribe solo si quieres reemplazarla.' : (f.placeholder || '');
    return f.type === 'pem'
      ? `<label class="adm-label full">${head}<textarea class="adm-input" ${name} rows="3" spellcheck="false" placeholder="${esc(ph)}"></textarea>${help}</label>`
      : `<label class="adm-label">${head}<input class="adm-input" type="password" ${name} autocomplete="new-password" placeholder="${esc(ph)}">${help}</label>`;
  }
  return `<label class="adm-label">${esc(f.label)}${req}<input class="adm-input" ${name} ${f.type === 'number' ? 'inputmode="numeric"' : ''} value="${esc(v ?? '')}" placeholder="${esc(f.placeholder || '')}">${help}</label>`;
}
function inCopy(label, val) { return `<div><b>${esc(label)}</b><div class="in-copy"><code title="${esc(val)}">${esc(val)}</code><button type="button" class="btn btn-ghost" style="padding:4px 9px" data-copy="${esc(val)}"><i class="fa-regular fa-copy"></i></button></div></div>`; }
function inExtra(id, it) {
  const x = it.extra || {};
  if (id === 'google_calendar') return `<div class="in-extra">
    <span>En Google Cloud › Credenciales › ID de cliente OAuth (aplicación web), registra estos dos datos:</span>
    ${inCopy('URI de redireccionamiento autorizado', x.redirect_uri)}${inCopy('Origen de JavaScript autorizado', x.origin)}
    <span>${x.users ? `<b>${x.users}</b> persona${x.users > 1 ? 's' : ''} conectada${x.users > 1 ? 's' : ''}.` : 'Nadie conectó su calendario todavía.'} Cada una lo hace desde <a href="/toolz/user_config.php#google">Mi perfil</a>.</span></div>`;
  if (id === 'whatsapp') return `<div class="in-extra"><span>Prueba con la plantilla <b>hello_world</b> (Meta la crea sola en inglés). En la cuenta de prueba, el número tiene que estar en la lista de destinatarios de Meta.</span>
    <div class="in-copy"><input class="adm-input" id="in-wa-to" placeholder="+54 9 11 5555 0000" style="flex:1"><button type="button" class="btn btn-ghost" data-wa-hello ${it.ready ? '' : 'disabled'}><i class="fa-brands fa-whatsapp"></i> Enviar hello_world</button></div></div>`;
  if (id === 'arca') return `<div class="in-extra"><span>Con el CAE, la factura deja de ser un comprobante interno. Se pide desde <a href="/toolz/facturacion/cobros.php#arca">Facturación › Cobros › Factura electrónica</a>.</span>
    ${x.ta ? `<span>Ticket de acceso guardado hasta ${esc(inDate(x.ta))}. <button type="button" class="in-clear" data-arca-reset>descartarlo</button></span>` : ''}</div>`;
  if (id === 'portales') return it.enabled ? `<div class="in-extra"><span>Pasa estos links a los portales o agregadores que leen feeds. Las fichas para compartir con un interesado salen de cada propiedad (botón "Compartir").</span>
    ${inCopy('Feed XML (formato Trovit)', x.feed_xml)}${inCopy('Feed JSON', x.feed_json)}<span><button type="button" class="in-clear" data-feed-regen>Generar otro link</button> (el anterior deja de funcionar)</span></div>` : '';
  if (id === 'youtube') return `<div class="in-extra"><span>Sirve para los entregables de Talentos con link de YouTube: trae vistas, me gusta y comentarios.</span>
    <div><button type="button" class="btn btn-ghost" data-yt ${it.ready ? '' : 'disabled'}><i class="fa-solid fa-rotate"></i> Actualizar métricas ahora</button></div></div>`;
  return '';
}
function inCard(id, it) {
  const pill = !it.module_on ? '<span class="in-pill off">Sin el módulo</span>' : it.ready ? '<span class="in-pill ok">Activa</span>' : it.enabled ? '<span class="in-pill warn">Faltan datos</span>' : '<span class="in-pill off">Apagada</span>';
  const main = Object.entries(it.defs).filter(([, f]) => !f.advanced), adv = Object.entries(it.defs).filter(([, f]) => f.advanced);
  const st = it.status || {}; const m = IN.msg[id];
  return `<form class="adm-card in-card" data-id="${id}" data-on-submit="inSave" data-submit-1="event"><div class="adm-card-head">
      <div class="in-ic" style="background:${esc(it.color)}"><i class="${esc(it.icon)}"></i></div>
      <div class="in-h"><div class="adm-card-title">${esc(it.name)} ${pill}</div><small>${esc(it.desc)}</small></div>
      <label class="adm-toggle" title="Activar o desactivar"><input type="checkbox" data-toggle ${it.enabled ? 'checked' : ''} ${it.module_on ? '' : 'disabled'}><span class="adm-toggle-track"></span><span class="adm-toggle-thumb"></span></label></div>
    <div class="adm-card-body">
      ${it.module_on ? '' : `<p class="rb-msg" style="margin:0 0 10px">Este sitio no tiene el módulo ${esc(it.module === 'propiedades' ? 'Propiedades' : 'Talentos')}: se puede dejar cargada, pero no se usa.</p>`}
      <div class="in-fields">${main.map(([k, f]) => inField(id, k, f, it.fields[k])).join('')}</div>
      ${adv.length ? `<details class="in-adv"><summary>Opciones avanzadas</summary><div class="in-fields" style="margin-top:8px">${adv.map(([k, f]) => inField(id, k, f, it.fields[k])).join('')}</div></details>` : ''}
      ${inExtra(id, it)}
      <div class="in-act"><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Guardar</button><button type="button" class="btn btn-ghost" data-test ${it.ready ? '' : 'disabled'}><i class="fa-solid fa-plug-circle-check"></i> Probar conexión</button></div>
      ${m ? `<div class="in-msg ${m.ok ? 'ok' : 'bad'}">${esc(m.text)}</div>` : ''}
      ${st.last_activity || st.last_error ? `<div class="in-st">${st.last_activity ? `<b>Última actividad</b><span>${esc(inDate(st.last_activity))} · ${esc(st.last_msg || '')}</span>` : ''}${st.last_error ? `<b>Último error</b><span class="err">${esc(inDate(st.last_error_at))} · ${esc(st.last_error)}</span>` : ''}</div>` : ''}
    </div></form>`;
}
function inLog(rows) {
  const names = Object.fromEntries(Object.entries(IN.s.items).map(([k, v]) => [k, v.name]));
  return rows.length ? `<table class="in-log">${rows.map(r => `<tr><td>${esc(inDate(r.t))}</td><td><i class="fa-solid ${r.ok ? 'fa-check' : 'fa-xmark'}" style="color:${r.ok ? '#22c55e' : '#f87171'}"></i></td><td><b>${esc(names[r.int] || r.int)}</b> · ${esc(r.msg)}</td></tr>`).join('')}</table>` : '<p class="rb-msg" style="margin:0">Sin actividad todavía.</p>';
}
// Mercado: catálogo por categoría con el estado real de cada una. Las que tienen su pantalla (Jira, IA, Monday, Pagos) abren esa pantalla.
const IN_ST = { ok: ['Conectada', 'ok'], warn: ['Revisar', 'warn'], off: ['Sin configurar', 'off'], soon: ['Próximamente', 'off'] };
function inMarket() {
  const s = IN.s, m = s.market || [], cat = IN.cat || '';
  const n = c => m.filter(x => x.cat === c).length, on = m.filter(x => x.status === 'ok').length;
  const list = m.filter(x => !cat || x.cat === cat);
  return `<div class="adm-card"><div class="adm-card-body">
    <div class="mk-top"><div><b class="mk-big">${on}</b> conectada${on === 1 ? '' : 's'} de ${m.filter(x => x.status !== 'soon').length} disponibles</div>
      <div class="mk-chips" role="tablist"><button type="button" role="tab" class="mk-chip${cat ? '' : ' on'}" data-cat="">Todas</button>${Object.entries(s.cats || {}).filter(([c]) => n(c)).map(([c, [l, ic]]) => `<button type="button" role="tab" class="mk-chip${cat === c ? ' on' : ''}" data-cat="${c}"><i class="${esc(ic)}"></i> ${esc(l)} <small>${n(c)}</small></button>`).join('')}</div></div>
    <div class="mk-grid">${list.map((x, i) => `<button type="button" class="mk-tile${x.status === 'soon' ? ' soon' : ''}" style="--i:${i}" data-mk="${esc(x.id)}" ${x.status === 'soon' ? 'disabled' : ''}>
        <span class="mk-ic" style="background:${esc(x.color)}"><i class="${esc(x.icon)}"></i></span>
        <span class="mk-t"><b>${esc(x.name)}</b><small>${esc((s.cats?.[x.cat] || [''])[0])}</small></span>
        <span class="in-pill ${IN_ST[x.status][1]}">${IN_ST[x.status][0]}</span>
        <span class="mk-d">${esc(x.desc)}</span>
        ${x.status === 'soon' ? '' : `<span class="mk-go">${x.go.card ? 'Configurar' : x.go.url ? 'Abrir Finanzas y pagos' : 'Abrir su sección'} <i class="fa-solid fa-arrow-right"></i></span>`}</button>`).join('')}</div></div></div>`;
}
function inGo(id) {
  const x = (IN.s.market || []).find(m => m.id === id); if (!x) return;
  if (x.go.url) { location.href = x.go.url; return; }
  if (x.go.section) { admShow(x.go.section); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
  const f = document.querySelector(`form.in-card[data-id="${x.go.card}"]`); if (!f) return;
  f.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  f.classList.remove('mk-flash'); void f.offsetWidth; f.classList.add('mk-flash');
}
function inRender() {
  const s = IN.s;
  document.getElementById('in-body').innerHTML = `<div class="in-grid">${inMarket()}
    <div class="adm-title" style="font-size:16px;margin:10px 0 0">Configuración</div>
    ${Object.entries(s.items).map(([k, it]) => inCard(k, it)).join('')}
    <div class="adm-card"><div class="adm-card-head" style="display:flex;justify-content:space-between;align-items:center"><div class="adm-card-title">Registro</div>
      <select class="adm-input" id="in-logf" style="width:auto"><option value="">Todas</option>${Object.entries(s.items).map(([k, v]) => `<option value="${k}" ${IN.logFilter === k ? 'selected' : ''}>${esc(v.name)}</option>`).join('')}</select></div>
      <div class="adm-card-body" id="in-logbox">${inLog(IN.logFilter ? s.log.filter(r => r.int === IN.logFilter) : s.log)}</div></div></div>`;
  const body = document.getElementById('in-body');
  body.querySelectorAll('[data-cat]').forEach(b => b.onclick = () => { IN.cat = b.dataset.cat; inRender(); });
  body.querySelectorAll('[data-mk]').forEach(b => b.onclick = () => inGo(b.dataset.mk));
  body.querySelectorAll('[data-copy]').forEach(b => b.onclick = () => { navigator.clipboard?.writeText(b.dataset.copy); admToast('Copiado.'); });
  body.querySelectorAll('form.in-card').forEach(f => {
    const id = f.dataset.id;
    f.querySelector('[data-toggle]').onchange = e => inDo(id, 'save', { id, enabled: e.target.checked, fields: {} }, e.target.checked ? 'Activada.' : 'Desactivada.');
    f.querySelector('[data-test]').onclick = () => inDo(id, 'test', { id });
    f.querySelectorAll('[data-clear]').forEach(b => b.onclick = () => { if (confirm('¿Borrar esta clave guardada?')) inDo(id, 'save', { id, fields: {}, clear: [b.dataset.clear] }, 'Clave borrada.'); });
    f.querySelector('[data-wa-hello]')?.addEventListener('click', () => inDo(id, 'wa_hello', { to: document.getElementById('in-wa-to').value }));
    f.querySelector('[data-feed-regen]')?.addEventListener('click', () => { if (confirm('El link actual del feed dejará de funcionar en los portales que lo usen. ¿Seguir?')) inDo(id, 'feed_regen', {}, 'Link nuevo generado.'); });
    f.querySelector('[data-yt]')?.addEventListener('click', () => inDo(id, 'yt_refresh', {}));
    f.querySelector('[data-arca-reset]')?.addEventListener('click', () => inDo(id, 'arca_reset_ta', {}, 'Ticket descartado. El próximo pedido saca uno nuevo.'));
  });
  document.getElementById('in-logf').onchange = async e => { IN.logFilter = e.target.value; const d = await inApi('log', { id: IN.logFilter }); document.getElementById('in-logbox').innerHTML = inLog(d.log); };
}
async function inDo(id, action, data, okText) {
  const btns = document.querySelectorAll(`form[data-id="${id}"] button`); btns.forEach(b => b.disabled = true);
  try {
    const d = await inApi(action, data); IN.s = d;
    if (d.message !== undefined) IN.msg[id] = { ok: !!d.ok, text: d.message }; else if (okText) { delete IN.msg[id]; admToast(okText); }
    inRender();
  } catch (e) { btns.forEach(b => b.disabled = false); }
}
function inSave(e) {
  e.preventDefault(); const f = e.target, id = f.dataset.id, defs = IN.s.items[id].defs, fields = {};
  for (const [k, d] of Object.entries(defs)) {
    const el = f.elements[k]; if (!el) continue;
    if (d.type === 'bool') fields[k] = el.checked;
    else if ((d.type === 'secret' || d.type === 'pem') && !el.value.trim()) continue;   // vacío = se queda la guardada
    else fields[k] = el.value;
  }
  inDo(id, 'save', { id, enabled: f.querySelector('[data-toggle]').checked, fields }, 'Guardado.');
}
async function inLoad() { if (IN.loaded) return; IN.loaded = true; try { IN.s = await inApi('get'); inRender(); } catch (e) { IN.loaded = false; } }
if (typeof SECTION_LOADERS !== 'undefined') SECTION_LOADERS.integraciones = () => { IN.loaded = false; inLoad(); };
else document.addEventListener('click', e => { if (e.target.closest('[data-sec="integraciones"]')) inLoad(); });
