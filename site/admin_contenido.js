/* admin_contenido.js — Admin › Contenido: contenido de usuarios eliminados o desactivados y pasarlo a otra persona (user_content_lib.php).
   También el diálogo de "Eliminar usuario", que deja elegir quién hereda. Usa accApi(), esc(), admToast() y S del panel. */
'use strict';
const UC = { src: [], kinds: {}, busy: false };
const ucBox = () => document.getElementById('uc-box');
const ucNum = (n, s, p) => n + ' ' + (n === 1 ? s : p);
const ucUserOpts = skip => '<option value="">Elegí un usuario…</option>' + S.users.filter(u => +u.active && u.id !== +skip).map(u => `<option value="${u.id}">${esc(uName(u))} (@${esc(u.username)})</option>`).join('');

async function ucLoad() {
  if (!ucBox() || typeof S === 'undefined' || !S) return;
  try { const r = await accApi('uc_sources'); UC.src = r.sources; UC.kinds = r.kinds; UC.moves = r.moves || []; } catch (e) { ucBox().innerHTML = '<div class="acc-muted">No se pudo cargar.</div>'; return; }
  ucRender();
}
/** El contenido de un usuario cualquiera (también activo), para pasarlo entero o en parte. */
async function ucOpenUser(id) {
  const r = await accApi('uc_of_user', { id }); UC.kinds = r.kinds;
  if (!Object.keys(r.counts).length) { admToast(uName(userById(id)) + ' no tiene contenido a su nombre'); return; }
  ucOpen({ key: 'u:' + id, name: uName(userById(id)), kinds: r.counts });
}
function ucRender() {
  ucBox().innerHTML = `<div class="uc-any"><label class="adm-label" for="uc-from">Pasar el contenido de un usuario</label><div class="uc-foot" style="margin:0"><select class="adm-select" id="uc-from" style="flex:1;min-width:180px">${ucUserOpts(0)}</select><button class="btn btn-ghost" type="button" id="uc-from-go"><i class="fa-solid fa-right-left"></i> Ver y pasar</button></div></div>
    <div class="acc-sub-h">De usuarios eliminados o desactivados</div>` + (UC.src.length ? UC.src.map((s, i) => `<div class="acc-list-row uc-row"><span class="uc-who"><b>${esc(s.name)}</b> <span class="badge badge-inactive">${s.state === 'gone' ? 'Eliminado' : 'Desactivado'}</span>
      <span class="acc-muted uc-kinds">${Object.entries(s.kinds).map(([k, n]) => `${esc(UC.kinds[k].name)}: ${n}`).join(' · ')}</span></span>
      <button class="btn btn-ghost btn-sm" type="button" data-uc="${i}"><i class="fa-solid fa-right-left"></i> Pasar a otro usuario</button></div>`).join('')
    : '<div class="acc-muted" style="padding:10px 0"><i class="fa-solid fa-circle-check" style="color:#22c55e"></i> Todo el contenido está a nombre de usuarios activos.</div>')
    + (UC.moves.length ? `<div class="acc-sub-h">Últimos pases</div>` + UC.moves.map(m => `<div class="acc-list-row uc-row"><span class="uc-who">${esc(m.from)} <i class="fa-solid fa-arrow-right" style="font-size:10px;opacity:.6"></i> <b>${esc(m.to)}</b><span class="acc-muted uc-kinds">${ucNum(m.total, 'elemento', 'elementos')} · ${esc(window.G7Time && G7Time.full ? G7Time.full(m.at) : m.at.slice(0, 16))}${m.by ? ' · ' + esc(m.by) : ''}</span></span>${m.undone ? '<span class="acc-muted">Deshecho</span>' : `<button class="btn btn-ghost btn-sm" type="button" data-undo="${m.id}"><i class="fa-solid fa-rotate-left"></i> Deshacer</button>`}</div>`).join('') : '');
  ucBox().querySelectorAll('[data-undo]').forEach(b => b.addEventListener('click', async () => {
    b.disabled = true;
    try { const r = await accApi('uc_undo', { id: +b.dataset.undo }); admToast(ucNum(r.back, 'elemento devuelto', 'elementos devueltos') + (r.left ? ' · ' + r.left + ' ya no se podían devolver' : ' ✓')); ucLoad(); } catch (e) { b.disabled = false; }
  }));
  document.getElementById('uc-from-go').addEventListener('click', () => { const id = +document.getElementById('uc-from').value; if (id) ucOpenUser(id); else admToast('Elegí un usuario', true); });
  ucBox().querySelectorAll('[data-uc]').forEach(b => b.addEventListener('click', () => ucOpen(UC.src[+b.dataset.uc])));
}
function ucDialog(html) {
  let m = document.getElementById('uc-dlg');
  if (!m) { m = document.createElement('div'); m.id = 'uc-dlg'; m.className = 'acc-creds'; document.body.append(m); document.addEventListener('keydown', e => { if (e.key === 'Escape') m.classList.remove('open'); }); }
  m.innerHTML = `<div class="acc-creds-box uc-box" role="dialog" aria-modal="true" aria-labelledby="uc-h">${html}</div>`;
  m.classList.add('open'); m.onclick = e => { if (e.target === m || e.target.closest('[data-uc-close]')) m.classList.remove('open'); };
  return m;
}
function ucOpen(s) {
  const m = ucDialog(`<div class="acc-creds-h" id="uc-h"><i class="fa-solid fa-right-left"></i> Pasar el contenido de ${esc(s.name)}</div>
    <p class="acc-help">Cambia a quién figura. No se mueven archivos ni se modifica el contenido.</p>
    <label class="adm-label" for="uc-to">Pasar a</label><select class="adm-select" id="uc-to">${ucUserOpts(s.key.startsWith('u:') ? s.key.slice(2) : 0)}</select>
    <div class="uc-list">${Object.entries(s.kinds).map(([k, n]) => `<div class="uc-kind" data-k="${k}"><label><input type="checkbox" data-all ${UC.kinds[k].private ? '' : 'checked'}> <i class="fa-solid ${UC.kinds[k].icon}"></i> ${esc(UC.kinds[k].name)} <span class="acc-muted">${n}</span></label>
      <button class="btn btn-ghost btn-sm" type="button" data-pick>Elegir</button>${UC.kinds[k].private ? '<div class="acc-muted uc-note">Puede tener archivos personales: quien los reciba los va a ver.</div>' : ''}<div class="uc-items" hidden></div></div>`).join('')}</div>
    <div class="uc-foot"><button class="btn btn-primary" type="button" id="uc-go">Pasar contenido</button><button class="btn btn-ghost" type="button" data-uc-close>Cancelar</button></div>`);
  m.querySelectorAll('.uc-kind').forEach(box => {
    const all = box.querySelector('[data-all]'), items = box.querySelector('.uc-items'), k = box.dataset.k;
    const boxes = () => [...items.querySelectorAll('input')];
    all.addEventListener('change', () => { all.indeterminate = false; boxes().forEach(x => { x.checked = all.checked; }); });
    items.addEventListener('change', () => { const n = boxes().filter(x => x.checked).length; all.checked = n === boxes().length; all.indeterminate = n > 0 && !all.checked; });
    box.querySelector('[data-pick]').addEventListener('click', async e => {
      if (items.childElementCount) { items.hidden = !items.hidden; return; }
      e.target.disabled = true;
      try {
        const r = await accApi('uc_items', { source: s.key, kind: k });
        items.innerHTML = r.items.map(x => `<label><input type="checkbox" value="${x.id}" ${all.checked ? 'checked' : ''}> ${esc(x.title)}</label>`).join('') + (s.kinds[k] > r.items.length ? `<div class="acc-muted">Se muestran los últimos ${r.items.length}.</div>` : '');
        items.hidden = false;
      } catch (err) {} finally { e.target.disabled = false; }
    });
  });
  m.querySelector('#uc-go').addEventListener('click', async e => {
    const to = +m.querySelector('#uc-to').value; if (!to) { admToast('Elegí a quién pasarlo', true); return; }
    const pick = {};
    m.querySelectorAll('.uc-kind').forEach(b => {
      const all = b.querySelector('[data-all]'), ids = [...b.querySelectorAll('.uc-items input:checked')].map(x => +x.value);
      if (all.checked && !all.indeterminate) pick[b.dataset.k] = true; else if (ids.length) pick[b.dataset.k] = ids;
    });
    if (!Object.keys(pick).length) { admToast('Elegí qué contenido pasar', true); return; }
    e.target.disabled = true;
    try { const r = await accApi('uc_move', { source: s.key, to, pick }); m.classList.remove('open'); admToast(ucNum(r.total, 'elemento pasado', 'elementos pasados') + ' a ' + uName(userById(to)) + ' ✓'); ucLoad(); }
    catch (err) { e.target.disabled = false; }
  });
}

/** Eliminar usuario: muestra qué tiene a su nombre y deja elegir quién lo hereda. */
async function ucDeleteUser(id) {
  const u = userById(id), r = await accApi('uc_of_user', { id });
  const ks = Object.entries(r.counts);
  const m = ucDialog(`<div class="acc-creds-h" id="uc-h"><i class="fa-solid fa-trash"></i> ¿Eliminar a ${esc(uName(u))}?</div>
    <p class="acc-help">No se puede deshacer.</p>
    ${ks.length ? `<p style="font-size:13px;margin:0 0 10px">Tiene contenido a su nombre: ${ks.map(([k, n]) => `${esc(r.kinds[k].name)} (${n})`).join(', ')}.</p>
      <label class="adm-label" for="uc-to">Pasar su contenido a</label><select class="adm-select" id="uc-to"><option value="">Nadie por ahora (se puede pasar después)</option>${ucUserOpts(id).replace(/^<option[^>]*>.*?<\/option>/, '')}</select>
      ${ks.some(([k]) => r.kinds[k].private) ? '<label class="uc-priv"><input type="checkbox" id="uc-priv"> Incluir su Drive personal (puede tener archivos personales)</label>' : ''}` : ''}
    <div class="uc-foot"><button class="btn btn-danger" type="button" id="uc-del">Eliminar usuario</button><button class="btn btn-ghost" type="button" data-uc-close>Cancelar</button></div>`);
  m.querySelector('#uc-del').addEventListener('click', async e => {
    e.target.disabled = true;
    try {
      const x = await accApi('user_delete', { id, transfer_to: +(m.querySelector('#uc-to')?.value || 0), transfer_private: !!m.querySelector('#uc-priv')?.checked });
      m.classList.remove('open'); admToast('Usuario eliminado' + (x.moved ? ' · ' + ucNum(x.moved, 'elemento pasado', 'elementos pasados') : '')); accDrawerClose(); accRefresh(); ucLoad();
    } catch (err) { e.target.disabled = false; }
  });
}
