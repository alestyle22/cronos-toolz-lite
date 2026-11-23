/* social/inbox.js — Social Studio · Community Inbox (#inbox, #inbox/c/<id>). It uses the helpers of app.js ($, esc, api, toast, S…).
   Three columns: conversations (boxes and filters) · thread with the reply box (reply / internal note) · details
   (status, priority, owner, department, tags, ticket). It refreshes on its own every 20 s while it is open. No inline code (CSP). */
'use strict';
const IB = { meta: null, f: { box: 'all', status: 'open', network: '', kind: '', q: '' }, list: [], counts: {}, cur: null, conv: null, mode: 'reply', file: null, timer: 0, busy: false };
const IB_KIND = { dm: ['Mensaje', 'fa-regular fa-envelope'], comment: ['Comentario', 'fa-regular fa-comment'] };
const ibNet = k => { const n = S.meta.networks[k]; return n ? `<i class="fa-brands ${n.icon}" style="color:${n.color}" title="${esc(n.name)}"></i>` : ''; };
const netOptions = () => (S.meta.connectable || ['instagram', 'facebook']).map(k => `<option value="${k}">${esc(S.meta.networks[k] ? S.meta.networks[k].name : k)}</option>`).join('');
const ibSt = id => (IB.meta.statuses.find(s => s.id === id) || { name: id, color: '#94a3b8' });
const ibAgo = s => { if (!s) return ''; const d = (Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 60000; return d < 1 ? 'ahora' : d < 60 ? Math.floor(d) + ' min' : d < 1440 ? Math.floor(d / 60) + ' h' : Math.floor(d / 1440) + ' d'; };
const ibAv = (name, pic) => pic ? `<img class="ib-av" src="${esc(pic)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="ib-av ph">${esc(String(name || '?').replace('@', '').slice(0, 1).toUpperCase())}</span>`;

async function openInbox() {
  const box = $('#ss-inbox');
  if (!IB.meta) {
    box.innerHTML = '<div class="ss-skel" style="grid-template-columns:1fr 2fr 1fr"><span style="height:420px"></span><span style="height:420px"></span><span style="height:420px"></span></div>';
    try { IB.meta = await api('inbox_meta'); } catch (e) { box.innerHTML = `<div class="ss-empty"><div class="art"><i class="fa-solid fa-inbox"></i></div><h3>No se pudo abrir el Inbox</h3><p>${esc(e.message)}</p></div>`; return; }
    drawInboxShell();
  }
  const m = location.hash.match(/^#inbox\/c\/(\d+)/);
  await ibLoad();
  if (m && IB.cur !== Number(m[1])) ibOpen(Number(m[1]));
  clearInterval(IB.timer);
  IB.timer = setInterval(() => { if (!document.hidden && !$('#ss-inbox').hidden) { ibLoad(true); if (IB.cur) ibOpen(IB.cur, true); } }, 20000);
}
function inboxStop() { clearInterval(IB.timer); IB.timer = 0; }

function drawInboxShell() {
  const M = IB.meta;
  if (!M.accounts.length) {
    $('#ss-inbox').innerHTML = `<div class="ss-card"><div class="ss-empty"><div class="art"><i class="fa-solid fa-inbox"></i></div><h3>Todavía no hay cuentas conectadas</h3><p>El Inbox muestra los mensajes directos y los comentarios reales de las páginas de Facebook y las cuentas de Instagram que conectes en Cuentas. No hay datos de ejemplo.</p><a class="ss-btn" href="#cuentas"><i class="fa-solid fa-plug"></i> Ir a Cuentas</a></div></div>`;
    return;
  }
  $('#ss-inbox').innerHTML = `<div class="ib">
    <aside class="ib-left">
      <div class="ib-boxes" role="tablist">${[['all', 'Todas'], ['mine', 'Mías'], ['unassigned', 'Sin asignar'], ['team', 'Mi depto.']].map(([k, l]) => `<button type="button" data-ib-box="${k}"${IB.f.box === k ? ' class="on"' : ''}>${l}<b data-ib-count="${k}"></b></button>`).join('')}</div>
      <div class="ib-filt">
        <label class="ss-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="ib-q" placeholder="Buscar persona o texto…" autocomplete="off"></label>
        <select id="ib-status" aria-label="Estado"><option value="open">Abiertas</option><option value="closed">Cerradas</option><option value="all">Todas</option>${M.statuses.map(s => `<option value="${esc(s.id)}">${esc(s.name)}</option>`).join('')}</select>
        <select id="ib-kind" aria-label="Tipo"><option value="">Mensajes y comentarios</option><option value="dm">Solo mensajes</option><option value="comment">Solo comentarios</option></select>
        <select id="ib-net" aria-label="Red"><option value="">Todas las redes</option>${netOptions()}</select>
        <button type="button" class="ss-icon" data-act="ib-sync" title="Traer mensajes de Meta" aria-label="Sincronizar"><i class="fa-solid fa-rotate"></i></button>
        ${can('config') ? '<button type="button" class="ss-icon" data-act="ib-settings" title="Estados y respuestas rápidas" aria-label="Ajustes del Inbox"><i class="fa-solid fa-sliders"></i></button>' : '<button type="button" class="ss-icon" data-act="ib-replies" title="Respuestas rápidas" aria-label="Respuestas rápidas"><i class="fa-solid fa-bolt"></i></button>'}
      </div>
      <div class="ib-list" id="ib-list" aria-live="polite"></div>
    </aside>
    <section class="ib-main" id="ib-main"><div class="ss-empty"><div class="art"><i class="fa-regular fa-comments"></i></div><h3>Elegí una conversación</h3><p>Los mensajes directos y los comentarios de tus cuentas llegan acá. Lo que escribas como <b>nota interna</b> nunca sale a la red.</p></div></section>
    <aside class="ib-side" id="ib-side"></aside></div>`;
  $('#ib-status').value = IB.f.status; $('#ib-kind').value = IB.f.kind; $('#ib-net').value = IB.f.network;
  [['ib-status', 'status'], ['ib-kind', 'kind'], ['ib-net', 'network']].forEach(([id, k]) => $('#' + id).addEventListener('change', e => { IB.f[k] = e.target.value; ibLoad(); }));
  let qt = 0; $('#ib-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { IB.f.q = e.target.value.trim(); ibLoad(); }, 300); });
}
async function ibLoad(quiet) {
  if (!$('#ib-list')) return;
  try {
    const d = await api('inbox_list', { filters: IB.f }, quiet);
    IB.list = d.items; IB.counts = d.counts || {};
  } catch (e) { if (!quiet) $('#ib-list').innerHTML = `<div class="ss-empty sm"><p>${esc(e.message)}</p></div>`; return; }
  const c = IB.counts;
  $$('[data-ib-count]').forEach(b => { const v = { all: c.total, mine: c.mine, unassigned: c.unassigned, team: '' }[b.dataset.ibCount]; b.textContent = v ? v : ''; });
  const n = $('#ss-inbox-n'); if (n) { n.hidden = !c.unread; n.textContent = c.unread || ''; }
  $('#ib-list').innerHTML = IB.list.length ? IB.list.map(x => {
    const s = ibSt(x.status), who = x.assignee_id && person(x.assignee_id);
    return `<button type="button" class="ib-item${x.id === IB.cur ? ' on' : ''}${x.unread ? ' unread' : ''}" data-ib-conv="${x.id}">
      ${ibAv(x.contact, x.contact_pic)}<span class="x"><span class="t"><b>${esc(x.contact)}</b><small>${esc(ibAgo(x.last_msg_at))}</small></span>
      <span class="s">${x.kind === 'comment' ? '<i class="fa-regular fa-comment"></i> ' : ''}${esc(x.last_text || '…')}</span>
      <span class="m">${ibNet(x.network)}<span>${esc(x.account)}</span>${x.priority === 'urgent' || x.priority === 'high' ? `<span class="ib-prio" style="--c:${IB.meta.priorities[x.priority].color}">${esc(IB.meta.priorities[x.priority].name)}</span>` : ''}<span class="ib-dot" style="--c:${esc(s.color)}" title="${esc(s.name)}"></span>${who ? `<span class="ss-av" style="--a:#a78bfa" title="${esc(who.name)}">${esc(who.name.slice(0, 1))}</span>` : ''}${x.unread ? `<b class="ib-unread">${x.unread}</b>` : ''}</span></span></button>`;
  }).join('') : `<div class="ss-empty sm"><div class="art" style="width:52px;height:52px;font-size:20px"><i class="fa-regular fa-face-smile"></i></div><p>${IB.f.q || IB.f.box !== 'all' || IB.f.kind || IB.f.network ? 'Nada con estos filtros.' : 'Todo al día: no hay conversaciones abiertas.'}</p></div>`;
}
async function ibOpen(id, quiet) {
  if (IB.busy && quiet) return;
  if (!quiet) { IB.cur = id; IB.file = null; history.replaceState(null, '', '#inbox/c/' + id); $$('.ib-item').forEach(b => b.classList.toggle('on', Number(b.dataset.ibConv) === id)); $('#ss-inbox .ib').classList.add('show-thread');
    $('#ib-main').innerHTML = '<div class="ss-skel" style="grid-template-columns:1fr;padding:16px"><span style="height:60px"></span><span style="height:260px"></span></div>'; }
  try {
    const d = await api('inbox_get', { id }, quiet);
    if (IB.cur !== id) return;
    const keep = quiet && $('#ib-text') ? $('#ib-text').value : null;
    IB.conv = d; drawThread(keep); drawSide();
    const it = IB.list.find(x => x.id === id); if (it && it.unread) { it.unread = 0; ibLoad(true); }
  } catch (e) { if (!quiet) { $('#ib-main').innerHTML = `<div class="ss-empty"><p>${esc(e.message)}</p></div>`; IB.cur = null; } }
}
function drawThread(keepText) {
  const { conv: c, msgs } = IB.conv, w = c.window;
  const canReply = can('reply');
  const winTxt = c.kind === 'comment' ? 'Respuesta pública al comentario' : w.ok ? (w.mode === 'human_agent' ? 'Fuera de las 24 h: se responde como agente humano, hasta el ' : 'Podés responder hasta el ') + esc(fmtWhen(w.until)) : 'Pasaron más de 24 h desde el último mensaje: Meta no deja responder hasta que la persona vuelva a escribir.';
  const atts = a => (a || []).map(x => x.url ? (/image|photo/.test(x.type) ? `<a href="${esc(x.url)}" target="_blank" rel="noopener"><img class="ib-img" src="${esc(x.url)}" alt="" loading="lazy" referrerpolicy="no-referrer"></a>` : `<a class="ss-tag" href="${esc(x.url)}" target="_blank" rel="noopener"><i class="fa-solid fa-paperclip"></i> ${esc(x.type)}</a>`) : `<span class="ss-tag"><i class="fa-solid fa-paperclip"></i> ${esc(x.type)} (Meta no dio el enlace)</span>`).join('');
  const bubble = m => m.dir === 'event' ? `<div class="ib-ev">${m.author ? '<b>' + esc(m.author) + '</b> ' : ''}${esc(m.body)} · ${esc(fmtWhen(m.at))}</div>`
    : `<div class="ib-msg ${m.dir}${m.state === 'failed' ? ' failed' : ''}"><div class="b">${m.dir === 'note' ? '<span class="ib-note-k"><i class="fa-solid fa-lock"></i> Nota interna</span>' : ''}${esc(m.body).replace(/\n/g, '<br>')}${atts(m.att)}</div>
       <small>${m.dir === 'in' ? esc(m.author || c.contact) : esc(m.author || 'Marca')} · ${esc(fmtWhen(m.at))}${m.state === 'failed' ? ' · <b>no se envió:</b> ' + esc(m.error) : m.state === 'sending' ? ' · enviando…' : ''}</small></div>`;
  $('#ib-main').innerHTML = `<header class="ib-h"><button type="button" class="ss-icon ib-back" data-act="ib-back" aria-label="Volver a la lista"><i class="fa-solid fa-arrow-left"></i></button>${ibAv(c.contact, c.contact_pic)}
      <div class="x"><b>${esc(c.contact)}</b><small>${ibNet(c.network)} ${esc(c.account)} · <i class="${IB_KIND[c.kind][1]}"></i> ${IB_KIND[c.kind][0]}${c.post_link ? ` · <a href="${esc(c.post_link)}" target="_blank" rel="noopener">ver la publicación</a>` : ''}</small></div></header>
    ${c.post_text ? `<div class="ib-post"><i class="fa-regular fa-image"></i> ${esc(c.post_text)}</div>` : ''}
    <div class="ib-thread" id="ib-thread">${msgs.length ? msgs.map(bubble).join('') : '<div class="ss-empty sm"><p>Sin mensajes todavía.</p></div>'}</div>
    <div class="ib-comp${IB.mode === 'note' ? ' note' : ''}">
      <div class="ib-comp-tabs"><button type="button" data-ib-mode="reply" class="${IB.mode === 'reply' ? 'on' : ''}"${canReply ? '' : ' disabled title="Responder pide el permiso correspondiente"'}><i class="fa-solid fa-reply"></i> Responder</button><button type="button" data-ib-mode="note" class="${IB.mode === 'note' ? 'on' : ''}"><i class="fa-solid fa-lock"></i> Nota interna</button>
        <span class="ib-win${IB.mode === 'reply' && !w.ok ? ' bad' : ''}">${IB.mode === 'note' ? 'Solo la ve el equipo. Mencioná con @usuario.' : winTxt}</span></div>
      <textarea id="ib-text" rows="3" placeholder="${IB.mode === 'note' ? 'Nota para el equipo (nunca sale a la red)…' : 'Escribí la respuesta…'}"${IB.mode === 'reply' && (!w.ok || !canReply) ? ' disabled' : ''}></textarea>
      ${IB.file ? `<div class="ib-file"><i class="fa-regular fa-image"></i> ${esc(IB.file.name)} <button type="button" class="ss-link" data-act="ib-unfile">quitar</button></div>` : ''}
      <div class="ib-comp-a">${IB.mode === 'reply' && canReply ? `<button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="ib-quick"><i class="fa-solid fa-bolt"></i> Respuestas rápidas</button>
        ${IB.meta.ai ? '<button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="ib-ai" title="Sugiere un texto; no se envía solo"><i class="fa-solid fa-wand-magic-sparkles"></i> Sugerir con IA</button>' : ''}
        ${c.kind === 'dm' ? '<label class="ss-btn ss-btn-ghost ss-btn-sm" title="Imagen (JPG, PNG o GIF)"><i class="fa-regular fa-image"></i> Imagen<input type="file" id="ib-file" accept="image/jpeg,image/png,image/gif" hidden></label>' : ''}` : ''}
        <span class="sp"></span><button type="button" class="ss-btn" data-act="ib-send"${IB.mode === 'reply' && (!w.ok || !canReply) ? ' disabled' : ''}><i class="fa-solid ${IB.mode === 'note' ? 'fa-floppy-disk' : 'fa-paper-plane'}"></i> ${IB.mode === 'note' ? 'Guardar nota' : 'Enviar'}</button></div></div>`;
  const th = $('#ib-thread'); th.scrollTop = th.scrollHeight;
  if (keepText) $('#ib-text').value = keepText;
  const fi = $('#ib-file');
  if (fi) fi.addEventListener('change', async () => {
    const f = fi.files[0]; if (!f) return;
    const fd = new FormData(); fd.append('action', 'inbox_upload'); fd.append('id', c.id); fd.append('file', f);
    const r = await fetch('api.php', { method: 'POST', headers: { 'X-SS': '1' }, credentials: 'same-origin', body: fd }); const d = await r.json().catch(() => ({ error: 'No se pudo subir.' }));
    if (d.error) { toast(d.error, true); return; }
    IB.file = d.file; const t = $('#ib-text').value; drawThread(t);
  });
  $('#ib-text').addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); ibSend(); } });
}
function drawSide() {
  const c = IB.conv.conv, M = IB.meta;
  const opt = (v, l, sel) => `<option value="${esc(v)}"${String(v) === String(sel) ? ' selected' : ''}>${esc(l)}</option>`;
  const agents = can('assign') ? M.agents : M.agents.filter(a => a.id === S.meta.me || a.id === c.assignee_id);
  $('#ib-side').innerHTML = `<div class="ib-side-in">
    <div class="ib-sec"><h4>Estado</h4><select class="ss-in" data-ib-set="status">${M.statuses.map(s => opt(s.id, s.name, c.status)).join('')}</select>
      ${c.status !== M.statuses.find(s => s.type === 'closed').id ? `<button type="button" class="ss-btn ss-btn-sm ib-full" data-act="ib-resolve"><i class="fa-solid fa-check"></i> Marcar como ${esc(M.statuses.find(s => s.type === 'closed').name.toLowerCase())}</button>` : ''}
      <button type="button" class="ss-link" data-act="ib-unread"><i class="fa-regular fa-envelope"></i> Marcar como no leída</button></div>
    <div class="ib-sec"><h4>Prioridad</h4><select class="ss-in" data-ib-set="priority">${Object.entries(M.priorities).map(([k, p]) => opt(k, p.name, c.priority)).join('')}</select></div>
    <div class="ib-sec"><h4>Responsable</h4><select class="ss-in" data-ib-set="assignee_id">${opt('', 'Sin responsable', c.assignee_id || '')}${agents.map(a => opt(a.id, a.name + (a.id === S.meta.me ? ' (yo)' : ''), c.assignee_id || '')).join('')}</select>
      ${c.assignee_id !== S.meta.me ? '<button type="button" class="ss-link" data-act="ib-take"><i class="fa-solid fa-hand"></i> Tomarla yo</button>' : ''}</div>
    <div class="ib-sec"><h4>Departamento</h4><select class="ss-in" data-ib-set="team_id"${can('assign') ? '' : ' disabled'}>${opt('', 'Sin departamento', c.team_id || '')}${M.teams.map(t => opt(t.id, t.name, c.team_id || '')).join('')}</select></div>
    <div class="ib-sec"><h4>Etiquetas</h4><div class="ss-tags-in" id="ib-tags">${c.tags.map(t => `<span class="ss-tag" data-ib-tag="${esc(t)}">#${esc(t)}<button type="button" data-act="ib-untag" aria-label="Sacar">×</button></span>`).join('')}<input id="ib-tag-in" maxlength="30" placeholder="consulta, reclamo…" aria-label="Nueva etiqueta"></div></div>
    <div class="ib-sec"><h4>Tickets</h4>${IB.conv.tickets.map(t => `<a class="ib-tk" href="${esc(t.url)}" target="_blank" rel="noopener"><i class="fa-solid fa-ticket"></i> #${t.id} · ${esc(t.subject || 'ticket')} <small>${esc(t.status)}</small></a>`).join('') || '<p class="ss-hint">Sin tickets.</p>'}
      ${can('tickets') && M.ticket_opts ? '<button type="button" class="ss-btn ss-btn-ghost ss-btn-sm ib-full" data-act="ib-ticket"><i class="fa-solid fa-ticket"></i> Crear ticket</button>' : ''}</div>
    <div class="ib-sec ib-contact"><h4>Contacto</h4>${ibAv(c.contact, c.contact_pic)}<b>${esc(c.contact)}</b>${c.contact_username ? `<small>@${esc(c.contact_username)}</small>` : ''}<small>${ibNet(c.network)} ${esc(c.account)}</small></div></div>`;
  $$('[data-ib-set]').forEach(el => el.addEventListener('change', () => ibSet({ [el.dataset.ibSet]: el.value === '' ? 0 : (/_id$/.test(el.dataset.ibSet) ? Number(el.value) : el.value) })));
  $('#ib-tag-in').addEventListener('keydown', e => {
    if (e.key !== 'Enter' && e.key !== ',') return; e.preventDefault();
    const v = e.target.value.replace(/^#/, '').trim(); if (!v) return;
    ibSet({ tags: c.tags.concat([v]) });
  });
}
async function ibSet(body) {
  try { const d = await api('inbox_update', { id: IB.cur, ...body }); IB.conv = d; drawThread($('#ib-text') ? $('#ib-text').value : ''); drawSide(); ibLoad(true); } catch (e) { drawSide(); }
}
async function ibSend() {
  const t = $('#ib-text'); if (!t || IB.busy) return;
  const body = t.value.trim();
  if (!body && !(IB.mode === 'reply' && IB.file)) { toast(IB.mode === 'note' ? 'Escribí la nota.' : 'Escribí la respuesta.', true); return; }
  IB.busy = true; const btn = $('[data-act="ib-send"]'); btn.disabled = true;
  try {
    const d = IB.mode === 'note' ? await api('inbox_note', { id: IB.cur, body }) : await api('inbox_reply', { id: IB.cur, body, file_id: IB.file ? IB.file.id : 0 });
    IB.conv = d; IB.file = null; drawThread(''); drawSide(); ibLoad(true);
    toast(IB.mode === 'note' ? 'Nota guardada (no sale a la red)' : 'Enviado');
  } catch (e) { btn.disabled = false; ibOpen(IB.cur, true); }
  finally { IB.busy = false; }
}
function ibQuick() {
  const R = IB.meta.replies, cats = [...new Set(R.map(r => r.category || 'General'))];
  openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-bolt"></i> Respuestas rápidas</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b">${R.length ? cats.map(cat => `<h4 class="ib-qcat">${esc(cat)}</h4>${R.filter(r => (r.category || 'General') === cat).map(r => `<button type="button" class="ib-q" data-ib-quick="${r.id}"><b>${esc(r.title)}</b><span>${esc(r.body)}</span></button>`).join('')}`).join('')
      : '<div class="ss-empty sm"><p>Todavía no hay respuestas rápidas.</p></div>'}
    <p class="ss-hint">Variables: {nombre}, {usuario}, {marca}, {agente}, {cuenta}. Se completan con los datos de la conversación; el texto queda en la caja para revisarlo antes de enviar.</p></div>
    <div class="ss-dg-f"><button type="button" class="ss-btn ss-btn-ghost" data-act="ib-replies"><i class="fa-solid fa-pen"></i> Administrar</button><span class="sp"></span></div>`, 'sm');
}
function ibReplies() {
  const R = IB.meta.replies, brands = S.meta.brands;
  openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h">Respuestas rápidas</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b"><div class="ib-rlist">${R.map(r => `<div class="ib-r"><div><b>${esc(r.title)}</b> <small class="ss-hint">${esc(r.category || 'General')}${r.brand_id && brands.find(b => b.id === r.brand_id) ? ' · ' + esc(brands.find(b => b.id === r.brand_id).name) : ''} · usada ${r.uses} veces</small><p>${esc(r.body)}</p></div>
      ${can('reply') ? `<span class="row"><button type="button" class="ss-icon" data-ib-redit="${r.id}" aria-label="Editar"><i class="fa-solid fa-pen"></i></button><button type="button" class="ss-icon" data-ib-rdel="${r.id}" aria-label="Borrar"><i class="fa-solid fa-trash"></i></button></span>` : ''}</div>`).join('') || '<p class="ss-hint">Todavía no hay ninguna.</p>'}</div>
    ${can('reply') ? `<div class="ss-form" id="ib-rform" style="margin-top:14px"><input type="hidden" id="ibr-id" value="0">
      <label class="ss-f"><span>Nombre</span><input class="ss-in" id="ibr-title" maxlength="80" placeholder="Horarios de atención"></label>
      <label class="ss-f"><span>Categoría</span><input class="ss-in" id="ibr-cat" maxlength="40" placeholder="Consultas" list="ibr-cats"><datalist id="ibr-cats">${[...new Set(R.map(r => r.category).filter(Boolean))].map(c => `<option value="${esc(c)}">`).join('')}</datalist></label>
      <label class="ss-f"><span>Marca<small>vacía = todas</small></span><select class="ss-in" id="ibr-brand"><option value="">Todas</option>${brands.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select></label>
      <label class="ss-f full"><span>Texto<small>{nombre} {usuario} {marca} {agente} {cuenta}</small></span><textarea class="ss-in" id="ibr-body" maxlength="2000" rows="3" placeholder="¡Hola {nombre}! Gracias por escribirnos…"></textarea></label>
      <div class="full"><button type="button" class="ss-btn" data-act="ib-rsave"><i class="fa-solid fa-check"></i> Guardar respuesta</button></div></div>` : ''}</div>`);
}
function ibSettings() {
  const st = IB.meta.statuses.map(s => ({ ...s }));
  const draw = () => {
    openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-sliders"></i> Ajustes del Inbox</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="ss-dg-b"><div class="ss-sec"><h4>Estados de las conversaciones</h4><p class="ss-hint" style="margin:0 0 8px">Los "de cierre" sacan la conversación de Abiertas; si la persona vuelve a escribir, se reabre sola.</p>
      <div class="ss-sts">${st.map((s, i) => `<div class="ss-st"><span></span><input type="color" class="ss-in" data-ibs="color" data-i="${i}" value="${esc(s.color)}" style="padding:2px;height:32px" aria-label="Color"><input class="ss-in" data-ibs="name" data-i="${i}" value="${esc(s.name)}" maxlength="30" aria-label="Nombre">
        <select class="ss-in" data-ibs="type" data-i="${i}" aria-label="Tipo"><option value="open"${s.type === 'open' ? ' selected' : ''}>Abierta</option><option value="closed"${s.type === 'closed' ? ' selected' : ''}>De cierre</option></select><button type="button" class="ss-icon" data-ibs-del="${i}" aria-label="Sacar"><i class="fa-solid fa-xmark"></i></button><span></span></div>`).join('')}</div>
      <button type="button" class="ss-link" data-ibs-add="1"><i class="fa-solid fa-plus"></i> Agregar estado</button></div>
      <button type="button" class="ss-btn ss-btn-ghost" data-act="ib-replies"><i class="fa-solid fa-bolt"></i> Respuestas rápidas</button></div>
      <div class="ss-dg-f"><span class="sp"></span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button><button type="button" class="ss-btn" data-ibs-save="1"><i class="fa-solid fa-check"></i> Guardar</button></div>`);
  };
  const read = () => $$('[data-ibs]').forEach(el => { st[Number(el.dataset.i)][el.dataset.ibs] = el.value; });
  IB.stEdit = { st, draw, read }; draw();
}
function ibTicket() {
  const o = IB.meta.ticket_opts, c = IB.conv.conv;
  const opt = (v, l) => `<option value="${esc(v)}">${esc(l)}</option>`;
  openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-ticket"></i> Crear ticket desde la conversación</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b"><div class="ss-note"><i class="fa-solid fa-circle-info"></i><span>Se crea un ticket real en Trabajo con los últimos mensajes y el enlace a esta conversación, que queda vinculada.</span></div>
    <div class="ss-form"><label class="ss-f full"><span>Asunto</span><input class="ss-in" id="ibt-subject" maxlength="200" value="${esc('Inbox: ' + c.contact)}"></label>
      <label class="ss-f"><span>Categoría</span><select class="ss-in" id="ibt-cat">${opt('', 'Elegí una')}${o.cats.map(x => opt(x.id, x.name)).join('')}</select></label>
      <label class="ss-f"><span>Departamento</span><select class="ss-in" id="ibt-team">${opt('', 'El de la categoría')}${o.teams.map(x => opt(x.id, x.name)).join('')}</select></label>
      <label class="ss-f"><span>Responsable</span><select class="ss-in" id="ibt-as">${opt('', 'Que lo tome el departamento')}${o.people.map(x => opt(x.id, x.name)).join('')}</select></label>
      <label class="ss-f"><span>Prioridad</span><select class="ss-in" id="ibt-prio">${o.priorities.map(x => `<option value="${esc(x.id)}"${x.id === o.default_priority ? ' selected' : ''}>${esc(x.name)}</option>`).join('')}</select></label>
      <label class="ss-f full"><span>Qué hay que hacer</span><textarea class="ss-in" id="ibt-desc" rows="3" maxlength="5000"></textarea></label></div></div>
    <div class="ss-dg-f"><span class="sp"></span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button><button type="button" class="ss-btn" data-act="ib-ticket-go"><i class="fa-solid fa-check"></i> Crear ticket</button></div>`);
}

document.addEventListener('click', async e => {
  const b = e.target.closest('[data-ib-box],[data-ib-conv],[data-ib-mode],[data-ib-quick],[data-ib-redit],[data-ib-rdel],[data-ibs-del],[data-ibs-add],[data-ibs-save],[data-act^="ib-"]');
  if (!b) return;
  if (b.dataset.ibBox) { IB.f.box = b.dataset.ibBox; $$('[data-ib-box]').forEach(x => x.classList.toggle('on', x === b)); ibLoad(); return; }
  if (b.dataset.ibConv) { ibOpen(Number(b.dataset.ibConv)); return; }
  if (b.dataset.ibMode) { if (b.disabled) return; IB.mode = b.dataset.ibMode; drawThread($('#ib-text').value); return; }
  if (b.dataset.ibQuick) { try { const d = await api('inbox_reply_render', { id: IB.cur, reply_id: Number(b.dataset.ibQuick) }); closeModal(); IB.mode = 'reply'; drawThread(d.text); $('#ib-text').focus(); } catch (err) {} return; }
  if (b.dataset.ibRedit) { const r = IB.meta.replies.find(x => x.id === Number(b.dataset.ibRedit)); $('#ibr-id').value = r.id; $('#ibr-title').value = r.title; $('#ibr-cat').value = r.category; $('#ibr-brand').value = r.brand_id || ''; $('#ibr-body').value = r.body; $('#ibr-title').focus(); return; }
  if (b.dataset.ibRdel) { if (!confirm('¿Borrar esta respuesta rápida?')) return; try { IB.meta.replies = (await api('reply_delete', { id: Number(b.dataset.ibRdel) })).replies; ibReplies(); } catch (err) {} return; }
  if (b.dataset.ibsDel) { IB.stEdit.read(); IB.stEdit.st.splice(Number(b.dataset.ibsDel), 1); IB.stEdit.draw(); return; }
  if (b.dataset.ibsAdd) { IB.stEdit.read(); IB.stEdit.st.push({ id: '', name: 'Nuevo estado', color: '#8b5cf6', type: 'open' }); IB.stEdit.draw(); return; }
  if (b.dataset.ibsSave) { IB.stEdit.read(); try { IB.meta.statuses = (await api('inbox_statuses_save', { statuses: IB.stEdit.st })).statuses; closeModal(); drawInboxShell(); ibLoad(); if (IB.cur) ibOpen(IB.cur, true); toast('Estados guardados'); } catch (err) {} return; }
  switch (b.dataset.act) {
    case 'ib-send': ibSend(); break;
    case 'ib-back': $('#ss-inbox .ib').classList.remove('show-thread'); IB.cur = null; history.replaceState(null, '', '#inbox'); break;
    case 'ib-resolve': ibSet({ status: IB.meta.statuses.find(s => s.type === 'closed').id }); break;
    case 'ib-unread': await ibSet({ unread: 1 }); IB.cur = null; $('#ss-inbox .ib').classList.remove('show-thread'); $('#ib-main').innerHTML = ''; $('#ib-side').innerHTML = ''; history.replaceState(null, '', '#inbox'); break;
    case 'ib-take': ibSet({ assignee_id: S.meta.me }); break;
    case 'ib-untag': ibSet({ tags: IB.conv.conv.tags.filter(t => t !== b.parentElement.dataset.ibTag) }); break;
    case 'ib-quick': ibQuick(); break;
    case 'ib-replies': ibReplies(); break;
    case 'ib-settings': ibSettings(); break;
    case 'ib-rsave': try { IB.meta.replies = (await api('reply_save', { reply: { id: Number($('#ibr-id').value), title: $('#ibr-title').value, category: $('#ibr-cat').value, brand_id: Number($('#ibr-brand').value) || 0, body: $('#ibr-body').value } })).replies; ibReplies(); toast('Respuesta guardada'); } catch (err) {} break;
    case 'ib-ai': {
      const t = $('#ib-text'); b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Pensando…';
      try { const d = await api('inbox_suggest', { id: IB.cur, hint: t.value.trim() }); t.value = d.text; t.focus(); toast('Sugerencia lista: revisala antes de enviar'); } catch (err) {}
      b.disabled = false; b.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Sugerir con IA'; break;
    }
    case 'ib-unfile': IB.file = null; drawThread($('#ib-text').value); break;
    case 'ib-sync': b.disabled = true; try { const d = await api('inbox_sync'); toast(d.new ? `Llegaron ${d.new} mensajes nuevos` : 'No había mensajes nuevos'); if (d.errors.length) toast(d.errors[0], true); ibLoad(); } catch (err) {} b.disabled = false; break;
    case 'ib-ticket': ibTicket(); break;
    case 'ib-ticket-go': {
      const cat = Number($('#ibt-cat').value); if (!cat) { toast('Elegí una categoría.', true); return; }
      try { const d = await api('inbox_ticket', { id: IB.cur, cat_id: cat, team_id: Number($('#ibt-team').value) || 0, assignee_id: Number($('#ibt-as').value) || 0, priority: $('#ibt-prio').value, subject: $('#ibt-subject').value, description: $('#ibt-desc').value });
        closeModal(); IB.conv = d; drawThread($('#ib-text') ? $('#ib-text').value : ''); drawSide(); toast('Se creó el ticket #' + d.ticket + ' en Trabajo'); } catch (err) {}
      break;
    }
  }
});
