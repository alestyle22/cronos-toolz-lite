/* trabajo/jira.js — Sección "Jira" de Gestión de trabajo: los tickets de los proyectos de Jira elegidos en Admin › Jira API,
   con filtros (texto o clave, proyecto, estado, los míos), indicadores por estado y los contenidos de Promos vinculados.
   Solo lectura: cada ticket se abre en Jira. Se suma a SECS de trabajo.js (usa sus $, esc, api, ago, emptyBox, toast). */
'use strict';
const JF = { q: '', project: '', cat: '', mine: false };
try { Object.assign(JF, JSON.parse(localStorage.getItem('wk.jira') || '{}'), { q: '' }); } catch (e) {}
const JCAT = { new: ['Por hacer', '#64748b', 'fa-circle'], indeterminate: ['En curso', '#3b82f6', 'fa-person-digging'], done: ['Hecho', '#22c55e', 'fa-circle-check'] };
let jiraT = 0, jiraSeq = 0;

SECS.jira = async function renderJira() {
  const m = $('wk-main');
  m.innerHTML = `<div class="wk-h"><div><h1><i class="fa-brands fa-jira" style="color:#2684ff"></i> Jira</h1><p>Tickets de los proyectos de Jira conectados. Solo lectura: cada uno se abre en Jira.</p></div>
      <div class="wk-h-a"><button class="wk-btn" id="jr-reload"><i class="fa-solid fa-rotate"></i>Actualizar</button></div></div>
    <div class="tk-kpis" id="jr-kpis"></div>
    <div class="wk-filters jr-f">
      <input class="wk-in" id="jr-q" type="search" placeholder="Buscar por texto o clave (ej.: DIS-12)" value="${esc(JF.q)}">
      <select class="wk-in" id="jr-p"><option value="">Todos los proyectos</option></select>
      <div class="jr-seg" id="jr-cat" role="group" aria-label="Estado">${[['', 'Todos'], ['new', 'Por hacer'], ['indeterminate', 'En curso'], ['done', 'Hecho']].map(([k, l]) => `<button data-c="${k}" class="${JF.cat === k ? 'on' : ''}">${l}</button>`).join('')}</div>
      <label class="jr-tg"><input type="checkbox" id="jr-mine"${JF.mine ? ' checked' : ''}>Solo los míos</label>
    </div><div id="jr-list"><div class="wk-sk"></div><div class="wk-sk"></div></div>`;
  const save = () => { try { localStorage.setItem('wk.jira', JSON.stringify(JF)); } catch (e) {} };
  $('jr-q').addEventListener('input', e => { JF.q = e.target.value; clearTimeout(jiraT); jiraT = setTimeout(jiraLoad, 400); });
  $('jr-p').addEventListener('change', e => { JF.project = e.target.value; save(); jiraLoad(); });
  $('jr-mine').addEventListener('change', e => { JF.mine = e.target.checked; save(); jiraLoad(); });
  $$jr('#jr-cat button').forEach(b => b.addEventListener('click', () => { JF.cat = b.dataset.c; save(); $$jr('#jr-cat button').forEach(x => x.classList.toggle('on', x === b)); jiraLoad(); }));
  $('jr-reload').addEventListener("click", jiraLoad);
  await jiraLoad();
};
const $$jr = s => [...document.querySelectorAll(s)];

async function jiraLoad() {
  const seq = ++jiraSeq, box = $('jr-list'); if (!box) return;
  box.innerHTML = '<div class="wk-sk"></div><div class="wk-sk"></div>';
  let r;
  try { r = await api('issues', { q: JF.q, project: JF.project, cat: JF.cat, mine: JF.mine }, 'jira_api.php'); }
  catch (e) { if (seq === jiraSeq) box.innerHTML = emptyBox('fa-triangle-exclamation', `<b>No se pudo consultar Jira</b><br>${esc(e.message)}`); return; }
  if (seq !== jiraSeq) return;
  const sel = $('jr-p');
  if (sel.options.length === 1) { sel.innerHTML += r.projects.map(p => `<option value="${esc(p)}"${JF.project === p ? ' selected' : ''}>${esc(p)}</option>`).join(''); }
  const I = r.issues, n = c => I.filter(i => i.cat === c).length;
  $('jr-kpis').innerHTML = [
    ['fa-brands fa-jira', '#2684ff', 'Tickets', I.length, I.length === 100 ? 'los 100 más recientes' : 'con estos filtros'],
    ['fa-solid fa-circle', '#64748b', 'Por hacer', n('new'), 'categoría To Do'],
    ['fa-solid fa-person-digging', '#3b82f6', 'En curso', n('indeterminate'), 'categoría In Progress'],
    ['fa-solid fa-circle-check', '#22c55e', 'Hechos', n('done'), 'categoría Done'],
    ['fa-solid fa-clock', '#ef4444', 'Vencidos', I.filter(i => i.due && i.cat !== 'done' && i.due < W.today).length, 'con fecha límite pasada'],
    ['fa-solid fa-layer-group', '#a78bfa', 'Con contenido', I.filter(i => (r.contents[i.key] || []).length).length, 'vinculados en Promos'],
  ].map(([ic, c, l, v, s]) => `<div class="tk-kpi" style="--c:${c}"><span class="ic"><i class="${ic}"></i></span><span class="tx"><small>${l}</small><b>${v}</b><span>${s}</span></span></div>`).join('');
  if (r.mine_missing) { box.innerHTML = emptyBox('fa-user-slash', '<b>No encontramos tu usuario en Jira</b><br>Se busca por el email de tu usuario de Cronos. Puede que en Jira uses otro email o que lo tengas oculto.'); return; }
  if (!I.length) { box.innerHTML = emptyBox('fa-magnifying-glass', JF.q || JF.cat || JF.mine || JF.project ? '<b>Nada con esos filtros</b>' : '<b>No hay tickets en los proyectos elegidos</b>'); return; }
  box.innerHTML = `<div class="tk-list">${I.map((i, k) => { const c = JCAT[i.cat] || JCAT.new, late = i.due && i.cat !== 'done' && i.due < W.today, cs = r.contents[i.key] || [];
    return `<a class="tk-row jr-row${i.cat === 'done' ? ' done' : ''}${late ? ' late' : ''}" style="--c:${c[1]};--i:${k}" href="${esc(i.url)}" target="_blank" rel="noopener">
      <span class="jr-ic"><i class="fa-solid ${c[2]}"></i></span>
      <span class="mid"><span class="top"><b class="jr-k">${esc(i.key)}</b><span class="tt">${esc(i.summary)}</span></span>
        <span class="jr-chips"><span class="tk-chip dot" style="--c:${c[1]}">${esc(i.status)}</span>${i.type ? `<span class="tk-chip plain">${esc(i.type)}</span>` : ''}${i.priority ? `<span class="tk-chip plain"><i class="fa-solid fa-signal"></i>${esc(i.priority)}</span>` : ''}
        ${i.due ? `<span class="tk-chip" style="--c:${late ? '#ef4444' : '#64748b'}"><i class="fa-regular fa-calendar"></i>${esc(i.due)}</span>` : ''}
        ${cs.map(x => `<span class="tk-chip jr-ct" data-ct="${esc(x.name)}" style="--c:#a78bfa" title="Abrir en Promos"><i class="fa-solid fa-layer-group"></i>${esc(x.name)}</span>`).join('')}</span></span>
      <span class="end"><span class="when">${esc(ago(i.updated))}</span><span class="jr-as">${i.assignee ? esc(i.assignee) : '<em>Sin asignar</em>'}</span><i class="fa-solid fa-arrow-up-right-from-square jr-go"></i></span></a>`; }).join('')}</div>`;
  // Un contenido vinculado abre Promos con la búsqueda (no el ticket)
  box.querySelectorAll('.jr-ct').forEach(ch => ch.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); window.open('/toolz/promos/?search=' + encodeURIComponent(ch.dataset.ct), '_blank'); }));
}
