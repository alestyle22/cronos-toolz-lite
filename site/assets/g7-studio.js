/* assets/g7-studio.js — Comportamiento del kit "estudio" (g7-studio.css). Genérico: sin lógica de ninguna herramienta.
 *   select[data-st-chips]          dibuja chips con sus opciones y los mantiene en sincronía (el select queda oculto)
 *   input[data-st-presets="a,b,"]   chips que ponen ese valor (vacío = "Sin límite")
 *   input[type=range].st-range       pinta el avance de la pista (--fill)
 *   [data-st-watch="#list .item"]    agrega .has-items al escenario cuando aparecen resultados
 *   [data-st-pill]                   texto de la píldora: plantilla con {id} que se llena con el valor de cada campo */
(function () {
  'use strict';
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const fire = el => { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };

  function chipsForSelect(sel){
    if (sel.__st) return; sel.__st = true;
    const box = document.createElement('div'); box.className = sel.dataset.stChips === 'seg' ? 'st-seg' : 'st-chips';
    const draw = () => { box.innerHTML = ''; [...sel.options].forEach(o => { const b = document.createElement('button'); b.type = 'button'; b.className = 'st-chip' + (o.value === sel.value ? ' on' : ''); b.textContent = o.textContent.trim(); b.dataset.v = o.value;
      b.addEventListener('click', () => { if (sel.value === o.value) return; sel.value = o.value; fire(sel); }); box.append(b); }); };
    sel.classList.add('st-hide'); sel.after(box); draw();
    sel.addEventListener('change', () => box.querySelectorAll('.st-chip').forEach(b => b.classList.toggle('on', b.dataset.v === sel.value)));
  }
  function presets(inp){
    if (inp.__st) return; inp.__st = true;
    const box = document.createElement('div'); box.className = 'st-chips'; box.style.marginTop = '10px';
    const vals = inp.dataset.stPresets.split(','), unit = inp.dataset.stUnit || '';
    const sync = () => box.querySelectorAll('.st-chip').forEach(b => b.classList.toggle('on', b.dataset.v === String(inp.value)));
    vals.forEach(v => { const b = document.createElement('button'); b.type = 'button'; b.className = 'st-chip'; b.dataset.v = v; b.textContent = v === '' ? 'Sin límite' : (v === '0' && inp.dataset.stZero) ? inp.dataset.stZero : v + (unit ? ' ' + unit : '');
      b.addEventListener('click', () => { inp.value = v; fire(inp); sync(); }); box.append(b); });
    (inp.closest('.st-row') || inp).after(box); sync();
    inp.addEventListener('input', sync);
  }
  function rangeFill(r){
    if (r.__st) return; r.__st = true;
    const upd = () => { const min = +r.min || 0, max = +r.max || 100; r.style.setProperty('--fill', ((+r.value - min) * 100 / (max - min || 1)) + '%'); };
    r.addEventListener('input', upd); upd();
  }
  function watch(stage){
    if (stage.__st) return; stage.__st = true;
    const sel = stage.dataset.stWatch, upd = () => stage.classList.toggle('has-items', !!stage.querySelector(sel));
    new MutationObserver(upd).observe(stage, { childList: true, subtree: true }); upd();
  }
  function pill(el){
    if (el.__st) return; el.__st = true;
    const tpl = el.dataset.stPill, ids = [...tpl.matchAll(/\{([a-zA-Z0-9_-]+)\}/g)].map(m => m[1]);
    const upd = () => { el.querySelector('[data-st-pill-text]').textContent = tpl.replace(/\{([a-zA-Z0-9_-]+)\}/g, (_, id) => { const f = document.getElementById(id); if (!f) return ''; if (f.tagName === 'SELECT') return f.options[f.selectedIndex]?.textContent.trim() || ''; return f.value === '' ? (f.dataset.stEmpty || '—') : f.value; }); };
    ids.forEach(id => { const f = document.getElementById(id); if (f){ f.addEventListener('input', upd); f.addEventListener('change', upd); } }); upd();
  }
  // Zonas .st-drop: si la herramienta no maneja "soltar", los archivos van a su input y se dispara el cambio
  document.addEventListener('dragover', e => { const z = e.target.closest && e.target.closest('.st-drop'); if (z && z.querySelector('input[type=file]')){ e.preventDefault(); z.classList.add('over'); } });
  document.addEventListener('dragleave', e => { const z = e.target.closest && e.target.closest('.st-drop'); if (z && !z.contains(e.relatedTarget)) z.classList.remove('over'); });
  document.addEventListener('drop', e => {
    const z = e.target.closest && e.target.closest('.st-drop'); if (!z) return; z.classList.remove('over');
    if (e.defaultPrevented) return;   // la herramienta ya lo manejó
    const inp = z.querySelector('input[type=file]'); if (!inp || !e.dataTransfer || !e.dataTransfer.files.length) return;
    e.preventDefault();
    try { inp.files = e.dataTransfer.files; } catch (er) { return; }
    inp.dispatchEvent(new Event('change', { bubbles: true }));
  });
  // input[data-st-swatches]: lista de colores separados por coma, dibujada como muestras (con quitar y agregar)
  function swatches(inp){
    if (inp.__st) return; inp.__st = true;
    const box = document.createElement('div'); box.className = 'st-swatches'; inp.after(box);
    const list = () => inp.value.split(',').map(s => s.trim()).filter(Boolean);
    const set = arr => { inp.value = arr.join(','); fire(inp); draw(); };
    function draw(){
      box.innerHTML = '';
      list().forEach((c, i) => { const b = document.createElement('span'); b.className = 'st-sw'; b.style.setProperty('--sw', c); b.title = c;
        b.innerHTML = `<i></i><em>${c.replace(/[<>&"]/g, '')}</em><button type="button" aria-label="Quitar ${c.replace(/[<>&"]/g, '')}">×</button>`;
        b.querySelector('button').onclick = () => { const a = list(); a.splice(i, 1); set(a); }; box.append(b); });
      const add = document.createElement('label'); add.className = 'st-sw add'; add.title = 'Agregar color';
      add.innerHTML = '<i class="fa-solid fa-plus"></i><input type="color" value="#ff6600">';
      add.querySelector('input').addEventListener('change', ev => set(list().concat(ev.target.value)));
      box.append(add);
    }
    inp.addEventListener('change', draw); inp.addEventListener('blur', draw); draw();
  }
  // Sidebar del estudio: todas las herramientas (studio_lib.php → #g7-studio-tools), la actual marcada
  function rail(){
    let D; try { D = JSON.parse((document.getElementById('g7-studio-tools') || {}).textContent || 'null'); } catch (e) { D = null; }
    const host = document.querySelector('.st, .sv-wrap'); if (!D || !D.tools || !D.tools.length || !host || host.querySelector('.st-rail')) return;
    const here = (D.here || location.pathname).replace(/webp_converter\.php$/, 'webpmaker.php');
    const nav = document.createElement('nav'); nav.className = 'st-rail'; nav.setAttribute('aria-label', 'Herramientas del estudio');
    nav.innerHTML = '<a class="st-rail-home" href="/toolz/" data-n="Cronos" data-d="Volver al escritorio"><i class="fa-solid fa-house"></i></a><span class="st-rail-sep"></span>' +
      D.tools.map((t, i) => '<a class="st-rail-a' + (here === t.url ? ' on' : '') + '" style="--g:' + t.grad + '" href="' + t.url + '" data-n="' + t.name.replace(/"/g, '&quot;') + '" data-d="' + t.desc.replace(/"/g, '&quot;') + '"><i class="fa-solid ' + t.icon + '"></i>' + (i < 9 ? '<span class="st-rail-k">Alt ' + (i + 1) + '</span>' : '') + '</a>').join('');
    host.prepend(nav); host.classList.add('has-rail');
    const tip = document.createElement('div'); tip.className = 'st-rail-tip'; document.body.append(tip);
    nav.querySelectorAll('a').forEach(a => {
      a.addEventListener('mouseenter', () => { const r = a.getBoundingClientRect(); tip.innerHTML = '<b></b><small></small>'; tip.querySelector('b').textContent = a.dataset.n; tip.querySelector('small').textContent = a.dataset.d; tip.style.left = (r.right + 10) + 'px'; tip.style.top = (r.top + r.height / 2 - 22) + 'px'; tip.classList.add('on'); });
      a.addEventListener('mouseleave', () => tip.classList.remove('on'));
    });
    document.addEventListener('keydown', e => { if (!e.altKey || e.ctrlKey || e.metaKey) return; const n = parseInt(e.key, 10); if (!(n >= 1 && n <= D.tools.length)) return; e.preventDefault(); const t = D.tools[n - 1]; if (here !== t.url) location.href = t.url; });
  }
  const boot = () => {
    rail();
    $$('input[data-st-swatches]').forEach(swatches);
    $$('select[data-st-chips]').forEach(chipsForSelect);
    $$('input[data-st-presets]').forEach(presets);
    $$('input.st-range').forEach(rangeFill);
    $$('[data-st-watch]').forEach(watch);
    $$('[data-st-pill]').forEach(pill);
  };
  if (document.readyState !== 'loading') boot(); else document.addEventListener('DOMContentLoaded', boot);
})();
