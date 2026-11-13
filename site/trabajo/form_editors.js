/* form_editors.js — Editores compartidos del Portal de Solicitudes: condiciones Y/O y reglas de estimación.
   Los usan la configuración de categorías y automatizaciones (portal_admin.js) y el constructor (builder.js).
   Necesitan una función global esc(). Doc: toolz/SOLICITUDES.md */
'use strict';

/** Editor de reglas de estimación (lo usan las categorías y el constructor). fields = [{id,label,type,options}] para las condiciones. */
const PT_OPS = { add: 'Sumar', replace: 'Reemplazar la base', mult: 'Multiplicar', range: 'Por rangos de cantidad', per: 'Por unidad (× un número)', alt: 'Alternativas excluyentes' };
const PT_CMP = { eq: 'es', ne: 'no es', gt: 'mayor que', gte: 'mayor o igual', lt: 'menor que', lte: 'menor o igual', contains: 'contiene', ncontains: 'no contiene', empty: 'está vacío', filled: 'tiene valor', checked: 'está tildado', unchecked: 'no está tildado', before: 'antes de', after: 'después de' };
function ptRulesEditor(box, rules, fields, redraw) {
  const fsel = (cur, k, i, extra = '') => `<select class="wk-in sm" data-r="${i}" data-k="${k}"${extra}><option value="">Campo…</option>${fields.map(f => `<option value="${esc(f.id)}"${cur === f.id ? ' selected' : ''}>${esc(f.label || f.id)}</option>`).join('')}</select>`;
  box.innerHTML = rules.map((r, i) => `<div class="pt-rule" data-i="${i}"><div class="pt-rule-h"><input class="wk-in sm" data-r="${i}" data-k="name" value="${esc(r.name || '')}" placeholder="Nombre (ej.: Con sonido)"><select class="wk-in sm" data-r="${i}" data-k="op">${Object.entries(PT_OPS).map(([k, l]) => `<option value="${k}"${r.op === k ? ' selected' : ''}>${l}</option>`).join('')}</select>
      ${['add', 'replace', 'per'].includes(r.op) ? `<input class="wk-in sm num" type="number" min="0" data-r="${i}" data-k="v" value="${r.v ?? 0}"><span>min</span>` : ''}${r.op === 'mult' ? `<span>×</span><input class="wk-in sm num" type="number" step="0.05" min="0" data-r="${i}" data-k="v" value="${r.v ?? 1}">` : ''}
      ${['range', 'per'].includes(r.op) ? fsel(r.field, 'field', i) : ''}<button type="button" class="pt-ic" data-rx="${i}" aria-label="Quitar regla"><i class="fa-solid fa-trash"></i></button></div>
    ${r.op === 'range' ? `<div class="pt-rule-sub">${(r.ranges || []).map((g, j) => `<span class="pt-rg">de <input class="wk-in sm num" type="number" data-g="${j}" data-gk="from" value="${g.from}"> a <input class="wk-in sm num" type="number" data-g="${j}" data-gk="to" value="${g.to ?? ''}" placeholder="∞"> → <input class="wk-in sm num" type="number" data-g="${j}" data-gk="v" value="${g.v}"> min <button type="button" class="pt-ic" data-gx="${j}"><i class="fa-solid fa-xmark"></i></button></span>`).join('')}<button type="button" class="wk-btn sm" data-gadd><i class="fa-solid fa-plus"></i> Rango</button></div>` : ''}
    ${r.op === 'alt' ? `<div class="pt-rule-sub">${(r.alts || []).map((a, j) => `<div class="pt-alt-row"><input class="wk-in sm" data-a="${j}" data-ak="name" value="${esc(a.name || '')}" placeholder="Opción"><input class="wk-in sm num" type="number" data-a="${j}" data-ak="v" value="${a.v}"> min <span>si</span><div class="pt-condbox" data-acond="${j}"></div><button type="button" class="pt-ic" data-ax="${j}"><i class="fa-solid fa-xmark"></i></button></div>`).join('')}<button type="button" class="wk-btn sm" data-aadd><i class="fa-solid fa-plus"></i> Alternativa</button></div>` : ''}
    <div class="pt-rule-when"><span>Se aplica</span><div class="pt-condbox" data-cond></div></div></div>`).join('') || '<p class="pt-muted">Sin reglas: vale el tiempo base.</p>';
  box.querySelectorAll('.pt-rule').forEach(el => {
    const i = +el.dataset.i, r = rules[i];
    el.querySelectorAll(`[data-r="${i}"]`).forEach(x => x.addEventListener('change', () => { const k = x.dataset.k; r[k] = ['v'].includes(k) ? +x.value : x.value; if (k === 'op') { if (r.op === 'range' && !r.ranges) r.ranges = [{ from: 1, to: null, v: 30 }]; if (r.op === 'alt' && !r.alts) r.alts = [{ name: '', v: 30, when: null }]; if (r.op === 'mult' && !r.v) r.v = 1.5; redraw(); } }));
    el.querySelector('[data-rx]').addEventListener('click', () => { rules.splice(i, 1); redraw(); });
    el.querySelectorAll('[data-g]').forEach(x => x.addEventListener('change', () => { const g = r.ranges[+x.dataset.g]; g[x.dataset.gk] = x.value === '' ? null : +x.value; }));
    el.querySelectorAll('[data-gx]').forEach(x => x.addEventListener('click', () => { r.ranges.splice(+x.dataset.gx, 1); redraw(); }));
    el.querySelector('[data-gadd]')?.addEventListener('click', () => { r.ranges.push({ from: 0, to: null, v: 30 }); redraw(); });
    el.querySelectorAll('[data-a]').forEach(x => x.addEventListener('change', () => { r.alts[+x.dataset.a][x.dataset.ak] = x.dataset.ak === 'v' ? +x.value : x.value; }));
    el.querySelectorAll('[data-ax]').forEach(x => x.addEventListener('click', () => { r.alts.splice(+x.dataset.ax, 1); redraw(); }));
    el.querySelector('[data-aadd]')?.addEventListener('click', () => { r.alts.push({ name: '', v: 30, when: null }); redraw(); });
    el.querySelectorAll('[data-acond]').forEach(cb => ptCondEditor(cb, r.alts[+cb.dataset.acond].when, fields, c => { r.alts[+cb.dataset.acond].when = c; }));
    ptCondEditor(el.querySelector('[data-cond]'), r.when, fields, c => { r.when = c; }, 'siempre');
  });
}
/** Editor de condiciones Y/O (visibilidad, reglas y automatizaciones). onChange(cond|null). */
function ptCondEditor(box, cond, fields, onChange, emptyLabel = 'siempre') {
  let c = cond ? JSON.parse(JSON.stringify(cond)) : null;
  const val = (r, i) => {
    const f = fields.find(x => x.id === r.f);
    if (['empty', 'filled', 'checked', 'unchecked'].includes(r.c)) return '';
    if (f && f.options && f.options.length) return `<select class="wk-in sm" data-ci="${i}" data-ck="v">${f.options.map(o => `<option value="${esc(o.v)}"${String(r.v) === String(o.v) ? ' selected' : ''}>${esc(o.l)}</option>`).join('')}</select>`;
    return `<input class="wk-in sm" data-ci="${i}" data-ck="v" value="${esc(Array.isArray(r.v) ? r.v.join(',') : r.v ?? '')}" type="${f && f.type === 'date' ? 'date' : f && f.type === 'number' ? 'number' : 'text'}" placeholder="valor">`;
  };
  const draw = () => {
    if (!c || !c.rules.length) { box.innerHTML = `<span class="pt-muted">${emptyLabel}</span><button type="button" class="wk-btn sm" data-cadd><i class="fa-solid fa-plus"></i> Condición</button>`; }
    else box.innerHTML = `${c.rules.length > 1 ? `<select class="wk-in sm pt-andor" data-op><option value="and"${c.op === 'and' ? ' selected' : ''}>si se cumplen TODAS</option><option value="or"${c.op === 'or' ? ' selected' : ''}>si se cumple ALGUNA</option></select>` : '<span class="pt-muted">si</span>'}
      ${c.rules.map((r, i) => `<div class="pt-cond">${`<select class="wk-in sm" data-ci="${i}" data-ck="f"><option value="">Campo…</option>${fields.map(f => `<option value="${esc(f.id)}"${r.f === f.id ? ' selected' : ''}>${esc(f.label || f.id)}</option>`).join('')}</select>`}<select class="wk-in sm" data-ci="${i}" data-ck="c">${Object.entries(PT_CMP).map(([k, l]) => `<option value="${k}"${r.c === k ? ' selected' : ''}>${l}</option>`).join('')}</select>${val(r, i)}<button type="button" class="pt-ic" data-cx="${i}"><i class="fa-solid fa-xmark"></i></button></div>`).join('')}<button type="button" class="wk-btn sm" data-cadd><i class="fa-solid fa-plus"></i> Y/O otra</button>`;
    box.querySelector('[data-cadd]').addEventListener('click', () => { c = c || { op: 'and', rules: [] }; const f = fields[0]; c.rules.push({ f: f ? f.id : '', c: f && f.type === 'checkbox' ? 'checked' : 'eq', v: f && f.options && f.options[0] ? f.options[0].v : '' }); draw(); onChange(c); });
    box.querySelector('[data-op]')?.addEventListener('change', e => { c.op = e.target.value; onChange(c); });
    box.querySelectorAll('[data-ci]').forEach(x => x.addEventListener('change', () => { const r = c.rules[+x.dataset.ci]; r[x.dataset.ck] = x.value; if (x.dataset.ck === 'f') { const f = fields.find(y => y.id === x.value); r.c = f && f.type === 'checkbox' ? 'checked' : 'eq'; r.v = f && f.options && f.options[0] ? f.options[0].v : ''; } if (x.dataset.ck !== 'v') draw(); onChange(c); }));
    box.querySelectorAll('[data-cx]').forEach(x => x.addEventListener('click', () => { c.rules.splice(+x.dataset.cx, 1); if (!c.rules.length) c = null; draw(); onChange(c); }));
  };
  draw();
}
