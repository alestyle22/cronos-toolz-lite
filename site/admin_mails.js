/* admin_mails.js — Admin › Mails (admin_mails.php). Usa esc() y admToast() del panel. Habla con mails_api.php.
   Plantillas: lista agrupada + editor con variables y vista previa en vivo (iframe aislado). Diseño: color, logo, pie, remitente.
   Enviados: registro de los últimos 30 días. */
'use strict';
const ML = { s: null, tab: 'tpl', key: '', log: [], logMore: false, timer: 0, loaded: false };
async function mlApi(action, data = {}) {
  let d;
  try {
    const r = await fetch('/toolz/mails_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Mails': '1' }, credentials: 'same-origin', body: JSON.stringify({ ...data, action }) });
    d = await r.json();
  } catch (e) { d = { error: 'No se pudo conectar con el servidor.' }; }
  if (d.error) { admToast(d.error, true); throw new Error(d.error); }
  return d;
}
const mlItems = () => ML.s.groups.flatMap(g => g.items);
const mlItem = k => mlItems().find(i => i.key === k);
const mlBox = () => document.getElementById('ml-body');

// ── Plantillas ───────────────────────────────────────────
function mlRenderTpl() {
  const it = mlItem(ML.key) || mlItems()[0]; ML.key = it.key;
  mlBox().innerHTML = `<div class="ml-grid">
    <div><div class="ml-list" role="listbox" aria-label="Emails">${ML.s.groups.map(g => `<div class="ml-g">${esc(g.name)}</div>` + g.items.map(i =>
      `<button class="ml-it${i.key === ML.key ? ' on' : ''}" data-key="${esc(i.key)}" role="option" aria-selected="${i.key === ML.key}"><span>${esc(i.name)}</span>${i.custom ? '<span class="ml-badge">Editado</span>' : ''}</button>`).join('')).join('')}</div>
      ${ML.s.others.length ? `<div class="ml-list ml-others" style="position:static;margin-top:14px"><div class="ml-g">Se ajustan en su módulo</div>${ML.s.others.map(o => `<div><b>${esc(o.name)}</b>${esc(o.what)}<br><small>Dónde: ${esc(o.where)}</small></div>`).join('')}</div>` : ''}
    </div>
    <div class="ml-two">
      <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title">${esc(it.name)}</div></div><div class="adm-card-body">
        <div class="ml-meta">Le llega a: ${esc(it.to)}.${it.system_buttons ? ' Los botones (Fui yo, No fui yo, Bloquear mi cuenta…) los pone el sistema con enlaces seguros de un solo uso.' : ''}</div>
        <form id="ml-form" autocomplete="off">
          <div class="ml-f"><label for="ml-subject">Asunto</label><input id="ml-subject" name="subject" maxlength="200" value="${esc(it.subject)}"></div>
          <div class="ml-f"><label for="ml-title">Título</label><input id="ml-title" name="title" maxlength="160" value="${esc(it.title)}"></div>
          <div class="ml-f"><label for="ml-text">Texto</label><textarea id="ml-text" name="text" maxlength="1500">${esc(it.text)}</textarea></div>
          ${it.system_buttons ? '' : `<div class="ml-f"><label for="ml-button">Texto del botón</label><input id="ml-button" name="button" maxlength="60" value="${esc(it.button)}"></div>`}
          <div class="ml-f"><label>Variables (toca una para sumarla donde está el cursor)</label></div>
          <div class="ml-vars">${it.vars.map(v => `<button type="button" class="ml-var" data-var="${esc(v.v)}" title="${esc(v.d)}">{${esc(v.v)}}</button>`).join('')}</div>
          <div class="ml-acts">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Guardar</button>
            <button type="button" class="btn btn-ghost" id="ml-test"><i class="fa-solid fa-paper-plane"></i> Mandarme una prueba</button>
            ${it.custom ? '<button type="button" class="btn btn-ghost" id="ml-reset"><i class="fa-solid fa-rotate-left"></i> Volver al original</button>' : ''}
          </div>
        </form>
      </div></div>
      <div class="ml-prev"><div class="ml-subj">Asunto: <b id="ml-psubj"></b></div><iframe id="ml-frame" sandbox="" title="Vista previa del email"></iframe></div>
    </div></div>`;
  mlPreview();
}
let mlLastFocus = null;
function mlFormTpl() { const f = document.getElementById('ml-form'); if (!f) return {}; const o = {}; ['subject', 'title', 'text', 'button'].forEach(n => { if (f[n]) o[n] = f[n].value; }); return o; }
async function mlPreview(layout) {
  try {
    const j = await mlApi('preview', { key: ML.key, tpl: mlFormTpl(), layout: layout || undefined });
    const fr = document.getElementById(ML.tab === 'look' ? 'ml-lframe' : 'ml-frame'), sj = document.getElementById(ML.tab === 'look' ? 'ml-lsubj' : 'ml-psubj');
    if (fr) fr.srcdoc = j.html; if (sj) sj.textContent = j.subject;
  } catch (e) {}
}
const mlPreviewSoon = (layout) => { clearTimeout(ML.timer); ML.timer = setTimeout(() => mlPreview(layout), 350); };

// ── Diseño ───────────────────────────────────────────────
function mlRenderLook() {
  const L = ML.s.layout;
  mlBox().innerHTML = `<div class="ml-two">
    <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title">Diseño de todos los emails</div></div><div class="adm-card-body">
      <form id="ml-look" autocomplete="off">
        <div class="ml-f"><label for="ml-theme">Fondo</label><select id="ml-theme" name="theme"><option value="dark"${L.theme === 'dark' ? ' selected' : ''}>Oscuro</option><option value="light"${L.theme === 'light' ? ' selected' : ''}>Claro</option></select></div>
        <div class="ml-f"><label for="ml-accent">Color principal</label><input id="ml-accent" name="accent" type="color" value="${esc(L.accent)}" style="height:40px;padding:4px"></div>
        <div class="ml-f"><label for="ml-logo">Logo (dirección https:// de la imagen; vacío = sin logo)</label><input id="ml-logo" name="logo" value="${esc(L.logo)}" placeholder="https://…/logo.png"></div>
        <div class="ml-f"><label for="ml-from">Nombre del remitente (vacío = el nombre de la plataforma)</label><input id="ml-from" name="from_name" maxlength="80" value="${esc(L.from_name)}"></div>
        <div class="ml-f"><label for="ml-footer">Pie (por ejemplo, dirección o aviso legal)</label><textarea id="ml-footer" name="footer" maxlength="400" style="min-height:70px">${esc(L.footer)}</textarea></div>
        <div class="ml-acts"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Guardar diseño</button></div>
      </form>
    </div></div>
    <div class="ml-prev"><div class="ml-subj">Ejemplo · Asunto: <b id="ml-lsubj"></b></div><iframe id="ml-lframe" sandbox="" title="Vista previa del diseño"></iframe></div></div>`;
  mlLookPreview();
}
const mlLookVals = () => { const f = document.getElementById('ml-look'); return { theme: f.theme.value, accent: f.accent.value, logo: f.logo.value.trim(), from_name: f.from_name.value, footer: f.footer.value }; };
function mlLookPreview() { const k = ML.key; ML.key = 'sec.device'; mlPreview(mlLookVals()); ML.key = k; }

// ── Enviados ─────────────────────────────────────────────
function mlRenderLog() {
  mlBox().innerHTML = `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title">Enviados (últimos 30 días)</div></div><div class="adm-card-body">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <input id="ml-q" type="search" placeholder="Buscar por email o asunto" style="flex:1;min-width:200px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 11px;font:inherit;font-size:13px" aria-label="Buscar">
      <select id="ml-k" aria-label="Tipo" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font:inherit;font-size:13px">
        <option value="">Todos</option><option value="sec.">Seguridad</option><option value="task.">Tareas</option><option value="tk.">Tickets</option><option value="pt.">Portal de Solicitudes</option><option value="test:">Pruebas</option></select>
    </div><div id="ml-logt">Cargando…</div></div></div>`;
  mlLoadLog();
}
async function mlLoadLog(offset = 0) {
  const j = await mlApi('log', { q: document.getElementById('ml-q')?.value || '', key: document.getElementById('ml-k')?.value || '', offset });
  ML.log = offset ? ML.log.concat(j.rows) : j.rows; ML.logMore = j.more;
  const d = s => window.G7Time ? G7Time.dateTime(s * 1000) : new Date(s * 1000).toLocaleString('es-AR');
  document.getElementById('ml-logt').innerHTML = !ML.log.length ? '<p style="color:var(--g7-text-4);font-size:13px">Todavía no hay emails anotados. Se anotan desde esta versión.</p>'
    : `<div style="overflow-x:auto"><table class="ml-log"><thead><tr><th>Fecha</th><th>Para</th><th>Asunto</th><th class="ml-hide">Tipo</th><th>Resultado</th></tr></thead><tbody>${ML.log.map(r => `<tr>
        <td style="white-space:nowrap">${esc(d(r.at))}</td><td>${esc(r.to)}</td><td>${esc(r.subject)}</td>
        <td class="ml-hide">${r.test ? 'Prueba · ' : ''}${esc(r.name || r.key || 'Otro')}</td>
        <td>${r.ok ? '<span class="ml-ok"><i class="fa-solid fa-check"></i> Enviado</span>' : '<span class="ml-bad"><i class="fa-solid fa-xmark"></i> Falló</span>'}</td></tr>`).join('')}</tbody></table></div>
      ${ML.logMore ? '<button class="btn btn-ghost" id="ml-more" style="margin-top:10px">Ver más</button>' : ''}`;
}

function mlRender() {
  document.querySelectorAll('#sec-mails .ml-tab').forEach(t => { t.classList.toggle('on', t.dataset.tab === ML.tab); t.setAttribute('aria-selected', t.dataset.tab === ML.tab); });
  if (ML.tab === 'tpl') mlRenderTpl(); else if (ML.tab === 'look') mlRenderLook(); else mlRenderLog();
}
async function mlLoad() {
  if (!ML.loaded) { ML.s = await mlApi('state'); ML.loaded = true; }
  const k = (location.hash.match(/^#mails\/(.+)$/) || [])[1]; if (k && mlItem(k)) ML.key = k;
  mlRender();
}

// Eventos (delegados en la sección: sin atributos en línea, por la CSP)
(() => {
  const sec = document.getElementById('sec-mails'); if (!sec) return;
  sec.addEventListener('click', async ev => {
    const t = ev.target.closest('.ml-tab'); if (t) { ML.tab = t.dataset.tab; mlRender(); return; }
    const it = ev.target.closest('.ml-it'); if (it) { ML.key = it.dataset.key; mlRenderTpl(); return; }
    const v = ev.target.closest('.ml-var');
    if (v) {
      const el = mlLastFocus && document.contains(mlLastFocus) ? mlLastFocus : document.getElementById('ml-text');
      const ins = '{' + v.dataset.var + '}', s = el.selectionStart ?? el.value.length, e2 = el.selectionEnd ?? s;
      el.value = el.value.slice(0, s) + ins + el.value.slice(e2); el.focus(); el.setSelectionRange(s + ins.length, s + ins.length); mlPreviewSoon(); return;
    }
    const b = ev.target.closest('button'); if (!b) return;
    try {
      if (b.id === 'ml-test') { b.disabled = true; const j = await mlApi('test', { key: ML.key }); admToast('Te mandamos la prueba a ' + j.to + ' (con los textos guardados)'); b.disabled = false; }
      else if (b.id === 'ml-reset') { if (!confirm('¿Volver a los textos originales de este email?')) return; ML.s = { ...ML.s, ...(await mlApi('reset', { key: ML.key })) }; mlRenderTpl(); admToast('Listo, volvió al original'); }
      else if (b.id === 'ml-more') { b.disabled = true; await mlLoadLog(ML.log.length); }
    } catch (x) { b.disabled = false; }
  });
  sec.addEventListener('focusin', ev => { if (ev.target.matches('#ml-form input, #ml-form textarea')) mlLastFocus = ev.target; });
  sec.addEventListener('input', ev => {
    if (ev.target.closest('#ml-form')) mlPreviewSoon();
    else if (ev.target.closest('#ml-look')) { clearTimeout(ML.timer); ML.timer = setTimeout(mlLookPreview, 350); }
    else if (ev.target.id === 'ml-q') { clearTimeout(ML.timer); ML.timer = setTimeout(() => mlLoadLog().catch(() => {}), 350); }
  });
  sec.addEventListener('change', ev => { if (ev.target.id === 'ml-k') mlLoadLog().catch(() => {}); else if (ev.target.closest('#ml-look')) mlLookPreview(); });
  sec.addEventListener('submit', async ev => {
    ev.preventDefault();
    const btn = ev.target.querySelector('[type=submit]'); btn.disabled = true;
    try {
      if (ev.target.id === 'ml-form') { ML.s = { ...ML.s, ...(await mlApi('save', { key: ML.key, ...mlFormTpl() })) }; mlRenderTpl(); admToast('Email guardado ✓'); }
      else if (ev.target.id === 'ml-look') { ML.s = { ...ML.s, ...(await mlApi('layout_save', mlLookVals())) }; admToast('Diseño guardado ✓'); btn.disabled = false; }
    } catch (x) { btn.disabled = false; }
  });
})();
if (typeof SECTION_LOADERS !== 'undefined') SECTION_LOADERS.mails = () => { mlLoad().catch(() => {}); };
