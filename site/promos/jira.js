/**
 * promos/jira.js — Jira en el detalle de un contenido de Promos: issues vinculados (con su estado al día) y buscador para
 * vincular más. Solo aparece si Admin › Jira API › "Usar Jira en Cronos" tiene Promos prendido (promos/jira_api.php).
 * Se monta sobre openDetail sin tocar el resto del detalle. Los textos de Jira se pintan escapados.
 */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const CAT = { new: ['Por hacer', '#64748b'], indeterminate: ['En curso', '#3b82f6'], done: ['Hecho', '#22c55e'] };
  let ST = null, cur = 0, timer = 0;
  const api = async (action, data = {}) => {
    const r = await fetch('/toolz/promos/jira_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...data }) });
    const j = await r.json().catch(() => ({ error: 'El servidor respondió con un error (' + r.status + ').' }));
    if (!r.ok || j.error) throw new Error(j.error || 'No se pudo completar');
    return j;
  };
  const say = (msg, bad) => (typeof promoToast === 'function' ? promoToast(msg, bad ? 'error' : 'ok') : alert(msg));

  function css() {
    if ($('pj-css')) return;
    const s = document.createElement('style'); s.id = 'pj-css';
    s.textContent = `#d-jira-wrap{margin-top:10px}
.pj-h{display:flex;align-items:center;gap:7px;font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
.pj-h i{color:#2684ff;font-size:12px}.pj-h .sp{flex:1}
.pj-add{background:rgba(38,132,255,.1);border:1px solid rgba(38,132,255,.3);color:#4c9aff;border-radius:6px;padding:3px 9px;font-size:10.5px;font-weight:700;cursor:pointer;text-transform:none;letter-spacing:0}
.pj-add:hover{background:rgba(38,132,255,.2)}
.pj-l{display:flex;flex-direction:column;gap:5px}
.pj-i{--c:#64748b;display:flex;align-items:center;gap:8px;padding:7px 9px;border-radius:8px;border:1px solid var(--g7-border-3);border-left:3px solid var(--c);background:var(--g7-bg-4);font-size:11.5px;text-decoration:none;color:var(--g7-text)}
.pj-i:hover{border-color:#2684ff}
.pj-i .k{font-weight:800;color:#4c9aff;white-space:nowrap}.pj-i .s{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--g7-text-2)}
.pj-i .st{font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:999px;background:color-mix(in srgb,var(--c) 18%,transparent);color:var(--c);white-space:nowrap}
.pj-i .x{background:none;border:0;color:var(--g7-text-4);cursor:pointer;padding:0 2px;font-size:12px}.pj-i .x:hover{color:#ef4444}
.pj-na{font-size:11px;color:var(--g7-text-4)}
#pj-modal{position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:650;display:none;align-items:center;justify-content:center;padding:20px}
#pj-modal .in{width:min(560px,100%);max-height:80vh;display:flex;flex-direction:column;background:var(--g7-bg-2);border:1px solid var(--g7-border-3);border-radius:14px;overflow:hidden}
#pj-modal .hd{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--g7-border-3);font-weight:800}
#pj-modal .hd i{color:#2684ff;font-size:18px}#pj-modal .hd .sp{flex:1}
#pj-modal .hd button{background:none;border:0;color:var(--g7-text-3);font-size:16px;cursor:pointer}
#pj-q{margin:12px 16px;width:calc(100% - 32px);background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;padding:9px 12px;color:var(--g7-text);font-size:13px;outline:0}
#pj-q:focus{border-color:#2684ff}
#pj-res{overflow-y:auto;padding:0 16px 14px;display:flex;flex-direction:column;gap:6px}
#pj-res button{--c:#64748b;display:flex;align-items:center;gap:9px;width:100%;text-align:left;padding:9px 10px;border-radius:9px;border:1px solid var(--g7-border-3);border-left:3px solid var(--c);background:var(--g7-bg-4);color:var(--g7-text);cursor:pointer;font:inherit;font-size:12.5px}
#pj-res button:hover{border-color:#2684ff;background:rgba(38,132,255,.08)}
#pj-res button:disabled{opacity:.5;cursor:default}
#pj-res .k{font-weight:800;color:#4c9aff}#pj-res .s{flex:1;min-width:0}#pj-res .m{font-size:10.5px;color:var(--g7-text-4);white-space:nowrap}`;
    document.head.appendChild(s);
  }
  const item = (l, x) => `<a class="pj-i" style="--c:${CAT[l.cat]?.[1] || '#64748b'}" href="${esc(l.url)}" target="_blank" rel="noopener" title="${esc(l.summary)}">
      <span class="k">${esc(l.key)}</span><span class="s">${esc(l.summary)}</span><span class="st">${esc(l.status || CAT[l.cat]?.[0] || '')}</span>
      ${x && ST.can_edit ? `<button class="x" data-un="${esc(l.key)}" title="Quitar el vínculo" aria-label="Quitar el vínculo con ${esc(l.key)}">✕</button>` : ''}</a>`;
  function draw(links) {
    const w = $('d-jira-wrap'); if (!w) return;
    w.innerHTML = `<div class="pj-h"><i class="fa-brands fa-jira"></i>Jira<span class="sp"></span>${ST.can_edit ? '<button class="pj-add" id="pj-add" type="button">+ Vincular issue</button>' : ''}</div>
      <div class="pj-l">${links.length ? links.map(l => item(l, true)).join('') : '<span class="pj-na">Sin issues vinculados.</span>'}</div>`;
    w.style.display = '';
    $('pj-add')?.addEventListener('click', openModal);
    w.querySelectorAll('[data-un]').forEach(b => b.addEventListener('click', async e => {
      e.preventDefault(); e.stopPropagation();
      if (!confirm(`¿Quitar el vínculo con ${b.dataset.un}? En Jira no cambia nada.`)) return;
      try { draw((await api('unlink', { content_id: cur, key: b.dataset.un })).links); say('Vínculo quitado'); } catch (err) { say(err.message, true); }
    }));
  }
  async function load(id) {
    cur = id; const w = $('d-jira-wrap'); if (!w) return;
    w.style.display = ''; w.innerHTML = '<div class="pj-h"><i class="fa-brands fa-jira"></i>Jira</div><span class="pj-na">Consultando Jira…</span>';
    try { const r = await api('links', { content_id: id }); if (cur === id) draw(r.links); }
    catch (e) { if (cur === id) w.innerHTML = `<div class="pj-h"><i class="fa-brands fa-jira"></i>Jira</div><span class="pj-na">${esc(e.message)}</span>`; }
  }
  function openModal() {
    let m = $('pj-modal');
    if (!m) {
      m = document.createElement('div'); m.id = 'pj-modal';
      m.innerHTML = `<div class="in" role="dialog" aria-modal="true" aria-label="Vincular un issue de Jira"><div class="hd"><i class="fa-brands fa-jira"></i>Vincular un issue de Jira<span class="sp"></span><button type="button" id="pj-x" aria-label="Cerrar">✕</button></div>
        <input id="pj-q" type="search" placeholder="Busca por texto o escribe la clave (ej.: ${esc((ST.projects[0] || 'DIS'))}-12)" autocomplete="off"><div id="pj-res"></div></div>`;
      document.body.appendChild(m);
      m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
      $('pj-x').addEventListener('click', () => { m.style.display = 'none'; });
      $('pj-q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 350); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape' && m.style.display === 'flex') m.style.display = 'none'; });
    }
    m.style.display = 'flex'; $('pj-q').value = ''; search(); setTimeout(() => $('pj-q').focus(), 30);
  }
  async function search() {
    const res = $('pj-res'), q = $('pj-q').value.trim(); res.innerHTML = '<span class="pj-na">Buscando en Jira…</span>';
    try {
      const { issues } = await api('search', { q });
      res.innerHTML = issues.length ? issues.map(i => `<button type="button" data-k="${esc(i.key)}" style="--c:${CAT[i.cat]?.[1] || '#64748b'}"><span class="k">${esc(i.key)}</span><span class="s">${esc(i.summary)}</span><span class="m">${esc(i.status)}${i.assignee ? ' · ' + esc(i.assignee) : ''}</span></button>`).join('')
        : `<span class="pj-na">${q ? 'No hay issues con eso en los proyectos elegidos.' : 'No hay issues en los proyectos elegidos.'}</span>`;
      res.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', async () => {
        b.disabled = true;
        try { draw((await api('link', { content_id: cur, key: b.dataset.k })).links); $('pj-modal').style.display = 'none'; say(`${b.dataset.k} vinculado`); }
        catch (e) { say(e.message, true); b.disabled = false; }
      }));
    } catch (e) { res.innerHTML = `<span class="pj-na">${esc(e.message)}</span>`; }
  }
  // Arranque: solo si Jira está prendido para Promos
  api('state').then(s => {
    ST = s; if (!s.on || typeof window.openDetail !== 'function') return;
    css();
    const orig = window.openDetail;
    window.openDetail = function (id) { const r = orig.apply(this, arguments); load(+id); return r; };
  }).catch(() => {});
})();
