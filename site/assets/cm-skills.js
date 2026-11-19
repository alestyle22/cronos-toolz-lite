/* assets/cm-skills.js — "Quién sabe qué" en Comunidad: buscar personas por herramientas y aptitudes.
   - Atajo en la columna izquierda y tarjeta en la derecha (lo más común en el equipo, con un buscador "¿Quién sabe…?").
   - Directorio en un modal: filtros con el selector de categorías (profile-picker.js), "todas" o "alguna", y tarjetas de
     personas con lo que coincide resaltado, su afinidad con vos y botones Perfil / Mensaje.
   - comunidad.php?habilidad=Figma abre el directorio con ese filtro (lo usan los chips de Mi perfil).
   Datos: comunidad.php › skills_people; listas en #pe-data (profile_fields_lib.php). Estilos: cm-skills.css. Doc: toolz/COMUNIDAD.md */
(function () {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const norm = s => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const moving = () => document.documentElement.classList.contains('g7-move');
  let PE = {}; try { PE = JSON.parse(document.getElementById('pe-data').textContent); } catch (e) {}
  const flat = t => { const o = []; const walk = v => Array.isArray(v) ? v.forEach(x => o.push(x)) : Object.values(v || {}).forEach(walk); walk(t); return o; };
  const TOOLS = flat(PE.tools), APTS = flat(PE.aptitudes);
  const isTool = v => TOOLS.some(t => norm(t) === norm(v));
  let people = null, me = 0, loading = null;
  const load = () => loading || (loading = fetch('/toolz/comunidad.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'skills_people' }) })
    .then(r => r.json()).then(d => { people = d.people || []; me = d.me; return people; }).catch(() => { people = []; return people; }));
  const av = (p, s) => p.avatar ? `<img src="${esc(p.avatar)}" alt="" style="width:${s}px;height:${s}px">` : `<span style="width:${s}px;height:${s}px;font-size:${Math.round(s * .42)}px">${esc((p.name || '?').charAt(0).toUpperCase())}</span>`;
  /** Lo más común en el equipo: [[nombre, cuántas personas, 't'|'a']] */
  function popular(n) {
    const c = new Map();
    (people || []).forEach(p => { p.tools.forEach(t => c.set('t|' + t, (c.get('t|' + t) || 0) + 1)); p.aptitudes.forEach(t => c.set('a|' + t, (c.get('a|' + t) || 0) + 1)); });
    return [...c.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])).slice(0, n).map(([k, v]) => [k.slice(2), v, k[0]]);
  }

  // ── Atajo a la izquierda ──
  function addShortcut() {
    const q = document.querySelector('.cme-quick'); if (!q || q.querySelector('#cms-open')) return;
    const b = document.createElement('button'); b.type = 'button'; b.className = 'nav-item'; b.id = 'cms-open';
    b.innerHTML = '<i class="fa-solid fa-user-gear"></i> Quién sabe qué'; b.addEventListener('click', () => open());
    const k = q.querySelector('#cme-kudos'); k ? k.before(b) : q.appendChild(b);
  }
  // ── Tarjeta a la derecha ──
  async function addCard() {
    const right = document.querySelector('.cme-card')?.parentNode || document.querySelector('.cm-today')?.parentNode; if (!right || right.querySelector('.cms-card')) return;
    const card = document.createElement('div'); card.className = 'cme-card cms-card';
    card.innerHTML = `<div class="cme-card-h"><i class="fa-solid fa-user-gear"></i> Quién sabe qué</div>
      <label class="cms-ask"><i class="fa-solid fa-magnifying-glass"></i><input type="text" placeholder="¿Quién sabe… Figma, Ventas?" maxlength="40" list="cms-dl" aria-label="Buscar quién sabe una herramienta o aptitud"></label>
      <datalist id="cms-dl">${TOOLS.concat(APTS).map(v => `<option value="${esc(v)}">`).join('')}</datalist>
      <div class="cms-pop"><span class="cms-sk"></span></div>
      <button type="button" class="cms-all">Ver el directorio <i class="fa-solid fa-arrow-right"></i></button>`;
    const first = right.querySelector('.cme-card'); first ? first.before(card) : right.appendChild(card);
    const inp = card.querySelector('input');
    const go = () => { const v = inp.value.trim(); if (!v) return open(); const hit = TOOLS.concat(APTS).find(x => norm(x) === norm(v)) || TOOLS.concat(APTS).find(x => norm(x).includes(norm(v))); open(hit ? [hit] : [], hit ? '' : v); inp.value = ''; };
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    inp.addEventListener('change', () => { if (TOOLS.concat(APTS).some(x => x === inp.value)) go(); });
    card.querySelector('.cms-all').addEventListener('click', () => open());
    await load();
    const pop = popular(10), box = card.querySelector('.cms-pop');
    box.innerHTML = pop.length ? pop.map(([v, n, k]) => `<button type="button" class="cms-tag ${k}" data-cms-f="${esc(v)}">${esc(v)} <b>${n}</b></button>`).join('') : '<p class="cme-muted">Todavía nadie cargó sus herramientas. <a href="/toolz/perfil.php">Cargá las tuyas</a>.</p>';
    box.addEventListener('click', e => { const t = e.target.closest('[data-cms-f]'); if (t) open([t.dataset.cmsF]); });
  }

  // ── Directorio ──
  let ov = null;
  async function open(pre = [], text = '') {
    if (ov) return;
    ov = document.createElement('div'); ov.className = 'cms-ov'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'Quién sabe qué');
    ov.innerHTML = `<div class="cms-modal">
      <header class="cms-head"><span class="cms-hic"><i class="fa-solid fa-user-gear"></i></span><div><h3>Quién sabe qué</h3><p>Elegí herramientas o aptitudes y mirá quién las tiene en el equipo.</p></div><button type="button" class="cms-x" data-cms-x aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
      <div class="cms-body">
        <aside class="cms-filters">
          <div class="cms-seg" role="tablist"><button type="button" class="on" data-cms-kind="t"><i class="fa-solid fa-screwdriver-wrench"></i> Herramientas <b id="cms-nt"></b></button><button type="button" data-cms-kind="a"><i class="fa-solid fa-star"></i> Aptitudes <b id="cms-na"></b></button></div>
          <div id="cms-pt"></div><div id="cms-pa" hidden></div>
        </aside>
        <section class="cms-res">
          <div class="cms-bar"><label class="cms-name"><i class="fa-solid fa-user"></i><input type="text" id="cms-q" placeholder="Filtrar por nombre o cargo" maxlength="40" value="${esc(text)}"></label>
            <div class="cms-mode" title="Con varios filtros"><button type="button" class="on" data-cms-mode="all">Tiene todo</button><button type="button" data-cms-mode="any">Alguna</button></div></div>
          <div class="cms-active" id="cms-active"></div>
          <div class="cms-list" id="cms-list"><div class="cms-skc"></div><div class="cms-skc"></div><div class="cms-skc"></div></div>
        </section>
      </div></div>`;
    document.body.appendChild(ov); document.documentElement.classList.add('pe-lock');
    if (moving()) ov.querySelector('.cms-modal').animate([{ opacity: 0, transform: 'translateY(22px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 360, easing: 'cubic-bezier(.2,.8,.2,1)' });
    await load(); if (!ov) return;
    // aptitudes escritas a mano por el equipo: van como una categoría más
    const extra = [...new Set(people.flatMap(p => p.aptitudes).filter(a => !APTS.some(x => norm(x) === norm(a))))].sort();
    const aGroups = extra.length ? { ...PE.aptitudes, 'Del equipo': { 'Escritas por el equipo': extra } } : PE.aptitudes;
    const st = { t: pre.filter(isTool), a: pre.filter(v => !isTool(v)), mode: 'all', q: text };
    const pk = {
      t: PFPicker.mount(ov.querySelector('#cms-pt'), { groups: PE.tools, selected: st.t, max: 20, placeholder: 'Buscar herramienta…', empty: 'Ninguna: se ve todo el equipo.', onChange: v => { st.t = v; draw(); } }),
      a: PFPicker.mount(ov.querySelector('#cms-pa'), { groups: aGroups, selected: st.a, max: 20, placeholder: 'Buscar aptitud…', empty: 'Ninguna: se ve todo el equipo.', onChange: v => { st.a = v; draw(); } }),
    };
    if (st.a.length && !st.t.length) kind('a');
    function kind(k) { ov.querySelectorAll('[data-cms-kind]').forEach(b => b.classList.toggle('on', b.dataset.cmsKind === k)); ov.querySelector('#cms-pt').hidden = k !== 't'; ov.querySelector('#cms-pa').hidden = k !== 'a'; }
    function draw() {
      const f = [...st.t.map(v => ['t', v]), ...st.a.map(v => ['a', v])], nq = norm(st.q.trim());
      ov.querySelector('#cms-nt').textContent = st.t.length || ''; ov.querySelector('#cms-na').textContent = st.a.length || '';
      ov.querySelector('#cms-active').innerHTML = f.length ? f.map(([k, v]) => `<button type="button" class="cms-chip ${k}" data-cms-rm="${k}|${esc(v)}">${esc(v)} <i class="fa-solid fa-xmark"></i></button>`).join('') + '<button type="button" class="cms-clear" data-cms-clear>Limpiar</button>' : '';
      const has = (list, v) => list.some(x => norm(x) === norm(v));
      let rows = people.filter(p => p.id !== me || f.length).map(p => {
        const hit = f.filter(([k, v]) => has(k === 't' ? p.tools : p.aptitudes, v));
        return { p, hit, n: hit.length };
      }).filter(r => (!nq || norm(r.p.name + ' ' + r.p.position + ' ' + r.p.username).includes(nq)) && (!f.length || (st.mode === 'all' ? r.n === f.length : r.n > 0)));
      if (!f.length) rows = rows.filter(r => r.p.tools.length || r.p.aptitudes.length || nq);
      rows.sort((a, b) => b.n - a.n || (b.p.aff ?? -1) - (a.p.aff ?? -1) || (b.p.tools.length + b.p.aptitudes.length) - (a.p.tools.length + a.p.aptitudes.length) || a.p.name.localeCompare(b.p.name, 'es'));
      const list = ov.querySelector('#cms-list');
      if (!rows.length) { list.innerHTML = `<div class="cms-empty"><span>🔎</span><b>${f.length ? 'Nadie tiene ' + (st.mode === 'all' && f.length > 1 ? 'todo eso junto' : 'eso') + ' todavía' : 'Todavía nadie cargó sus herramientas'}</b>${f.length > 1 && st.mode === 'all' ? '<button type="button" class="cms-clear" data-cms-mode="any">Probar con «Alguna»</button>' : '<a href="/toolz/perfil.php" class="cms-clear">Cargar las mías</a>'}</div>`; return; }
      const head = `<p class="cms-count">${rows.length} ${rows.length === 1 ? 'persona' : 'personas'}${f.length ? (st.mode === 'all' ? ' con todo lo elegido' : ' con alguna') : ' con herramientas o aptitudes cargadas'}</p>`;
      list.innerHTML = head + rows.slice(0, 120).map(({ p, hit }) => {
        const hs = new Set(hit.map(([k, v]) => k + norm(v)));
        const chips = [...p.tools.map(v => ['t', v]), ...p.aptitudes.map(v => ['a', v])].sort((x, y) => hs.has(y[0] + norm(y[1])) - hs.has(x[0] + norm(x[1])));
        const shown = chips.slice(0, 7), more = chips.length - shown.length;
        return `<article class="cms-p">
          <a class="cms-av${p.online ? ' on' : ''}" href="/toolz/perfil.php?u=${p.id}" data-g7-user="${p.id}">${av(p, 48)}</a>
          <div class="cms-pt"><a href="/toolz/perfil.php?u=${p.id}" data-g7-user="${p.id}"><b>${esc(p.name)}</b>${p.id === me ? ' <small>(vos)</small>' : ''}</a><small>${esc(p.position || '@' + p.username)}</small>
            <div class="cms-chips">${shown.map(([k, v]) => `<span class="cms-c ${k}${hs.has(k + norm(v)) ? ' hit' : ''}">${esc(v)}</span>`).join('')}${more > 0 ? `<span class="cms-c more">+${more}</span>` : ''}${!chips.length ? '<span class="cms-c none">Sin cargar</span>' : ''}</div></div>
          <div class="cms-side">${f.length ? `<span class="cms-match">${hit.length}/${f.length}</span>` : ''}${p.aff != null && p.aff > 0 ? `<span class="cms-aff" title="Afinidad con vos por herramientas y aptitudes">${p.aff}%</span>` : ''}
            <div class="cms-act"><a href="/toolz/perfil.php?u=${p.id}" title="Ver perfil" aria-label="Ver perfil de ${esc(p.name)}"><i class="fa-solid fa-id-card"></i></a>${p.id !== me ? `<a href="/toolz/chat/?u=${p.id}" title="Mandar mensaje" aria-label="Mandar mensaje a ${esc(p.name)}"><i class="fa-solid fa-paper-plane"></i></a>` : ''}</div></div>
        </article>`;
      }).join('');
      if (moving()) list.querySelectorAll('.cms-p').forEach((el, i) => i < 14 && el.animate([{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 300, delay: i * 25, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
    }
    ov.addEventListener('click', e => {
      if (e.target === ov || e.target.closest('[data-cms-x]')) return close();
      const kb = e.target.closest('[data-cms-kind]'); if (kb) return kind(kb.dataset.cmsKind);
      const m = e.target.closest('[data-cms-mode]'); if (m) { st.mode = m.dataset.cmsMode; ov.querySelectorAll('.cms-mode [data-cms-mode]').forEach(b => b.classList.toggle('on', b.dataset.cmsMode === st.mode)); return draw(); }
      const rm = e.target.closest('[data-cms-rm]'); if (rm) { const [k, v] = [rm.dataset.cmsRm[0], rm.dataset.cmsRm.slice(2)]; st[k] = st[k].filter(x => x !== v); pk[k].set(st[k]); return draw(); }
      if (e.target.closest('[data-cms-clear]')) { st.t = []; st.a = []; pk.t.set([]); pk.a.set([]); return draw(); }
    });
    ov.querySelector('#cms-q').addEventListener('input', e => { st.q = e.target.value; draw(); });
    draw();
  }
  function close() { if (!ov) return; ov.remove(); ov = null; document.documentElement.classList.remove('pe-lock'); }
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov && !document.querySelector('.pfp-ov')) close(); });

  window.CMSkills = { open };
  addShortcut(); addCard();
  const qs = new URLSearchParams(location.search);
  if (qs.get('habilidad')) open([qs.get('habilidad').slice(0, 40)]); else if (qs.has('quien')) open();
})();
