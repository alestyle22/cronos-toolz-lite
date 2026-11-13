/* form_render.js — Dibuja un formulario del Portal de Solicitudes (formato 2) y valida mientras se llena.
   Lo usan el portal (portal.js) y la vista previa del constructor (builder.js). Las reglas son las mismas que form_lib.php,
   pero quien decide es siempre el servidor: acá solo se muestran los errores al momento. Doc: toolz/SOLICITUDES.md */
'use strict';

(function () {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const VAL_TYPES = new Set(['text', 'textarea', 'number', 'email', 'phone', 'select', 'multiselect', 'radio', 'checkbox', 'date', 'time', 'color', 'file', 'image', 'url', 'priority', 'catpick', 'approval', 'hidden', 'person']);
  const COLS = { '1/1': 12, '3/4': 9, '2/3': 8, '1/2': 6, '1/3': 4, '1/4': 3 };
  const fmtMin = m => !m ? '0 min' : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h${m % 60 ? ' ' + (m % 60) + ' min' : ''}`;
  const fmtSize = b => b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(0) + ' KB' : (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
  const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 10);
  const dateLimit = v => v === 'today' ? today() : (v && v[0] === '+') ? new Date(Date.now() + (+v.slice(1)) * 864e5).toISOString().slice(0, 10) : v;

  // ── Condiciones (idénticas a pt_cond / pt_cmp de form_lib.php) ──
  function cmp(a, c, v) {
    const isArr = Array.isArray(a);
    const empty = a === null || a === undefined || a === '' || (isArr && !a.length);
    const num = x => { const s = String(x ?? '').replace(',', '.'); return s.trim() !== '' && !isNaN(+s) ? +s : null; };
    switch (c) {
      case 'empty': return empty;
      case 'filled': return !empty;
      case 'checked': return !empty && a !== '0' && a !== false;
      case 'unchecked': return empty || a === '0' || a === false;
      case 'eq': return isArr ? a.map(String).includes(String(v)) : String(a ?? '') === String(v ?? '');
      case 'ne': return isArr ? !a.map(String).includes(String(v)) : String(a ?? '') !== String(v ?? '');
      case 'in': return !isArr && (Array.isArray(v) ? v : [v]).map(String).includes(String(a ?? ''));
      case 'contains': return isArr ? a.map(String).includes(String(v)) : (String(v ?? '') !== '' && String(a ?? '').toLowerCase().includes(String(v).toLowerCase()));
      case 'ncontains': return !cmp(a, 'contains', v);
      case 'gt': case 'gte': case 'lt': case 'lte': {
        const x = num(a), y = num(v); if (x === null || y === null) return false;
        return c === 'gt' ? x > y : c === 'gte' ? x >= y : c === 'lt' ? x < y : x <= y;
      }
      case 'before': return !empty && !isArr && String(v ?? '') !== '' && String(a) < String(v);
      case 'after': return !empty && !isArr && String(v ?? '') !== '' && String(a) > String(v);
    }
    return false;
  }
  function cond(c, ans) {
    if (!c || !c.rules || !c.rules.length) return true;
    const or = c.op === 'or';
    for (const r of c.rules) {
      const ok = r.rules ? cond(r, ans) : cmp(ans[r.f], r.c, r.v);
      if (or && ok) return true;
      if (!or && !ok) return false;
    }
    return !or;
  }
  /** Elementos aplanados con la condición de su sección y de su grupo. */
  function items(schema) {
    const out = [];
    for (const sec of schema.sections || []) for (const it of sec.items || []) {
      out.push({ it, sec: sec.cond || null, grp: null });
      if (it.type === 'group') for (const sub of it.items || []) out.push({ it: sub, sec: sec.cond || null, grp: it.cond || null });
    }
    return out;
  }
  /** Ids visibles, iterando como el servidor (una condición puede depender de un campo oculto). */
  function visible(schema, ans) {
    const all = items(schema);
    let vis = null;
    for (let pass = 0; pass < 5; pass++) {
      const view = {};
      for (const k in ans) if (!vis || vis[k]) view[k] = ans[k];
      const nv = {};
      for (const x of all) nv[x.it.id] = cond(x.sec, view) && cond(x.grp, view) && cond(x.it.cond, view);
      if (vis && JSON.stringify(nv) === JSON.stringify(vis)) break;
      vis = nv;
    }
    return vis || {};
  }

  // ── Validación al momento (la definitiva es la del servidor) ──
  function check(it, v, ctx) {
    const va = it.valid || {}, lab = it.label || '';
    const isEmpty = v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length) || (it.type === 'checkbox' && v !== '1');
    if (it.type === 'approval' && !it.pick) return '';
    if (isEmpty) return it.required ? 'Completá este campo' : '';
    const s = String(v);
    switch (it.type) {
      case 'text': case 'textarea': {
        const max = va.maxlen ?? (it.type === 'text' ? 300 : 10000);
        if (s.length > max) return `Máximo ${max} caracteres`;
        if (va.minlen && s.length < va.minlen) return `Mínimo ${va.minlen} caracteres (vas ${s.length})`;
        if (va.pattern) { try { if (!new RegExp(va.pattern, 'u').test(s)) return va.pattern_msg || 'El formato no es válido'; } catch (e) {} }
        return '';
      }
      case 'number': {
        const n = +s.replace(',', '.'); if (isNaN(n)) return 'Tiene que ser un número';
        if (va.min !== undefined && n < va.min) return `Mínimo ${va.min}`;
        if (va.max !== undefined && n > va.max) return `Máximo ${va.max}`;
        if (va.step === 1 && Math.floor(n) !== n) return 'Tiene que ser un número entero';
        return '';
      }
      case 'email': return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s) ? '' : 'El email no es válido';
      case 'phone': return /^\+?[\d\s().-]{6,25}$/.test(s) ? '' : 'El teléfono no es válido';
      case 'url': return /^https?:\/\/\S{3,}$/i.test(s) ? '' : 'Tiene que ser un link (https://…)';
      case 'date':
        if (va.mindate && s < dateLimit(va.mindate)) return 'La fecha es anterior a la permitida';
        if (va.maxdate && s > dateLimit(va.maxdate)) return 'La fecha es posterior a la permitida';
        return '';
      case 'multiselect':
        if (va.mincount && v.length < va.mincount) return `Elegí al menos ${va.mincount}`;
        if (va.maxcount && v.length > va.maxcount) return `Elegí como mucho ${va.maxcount}`;
        return '';
      case 'file': case 'image':
        if ((v || []).some(f => f.uploading)) return 'Esperá que termine de subirse';
        if (va.maxfiles && v.length > va.maxfiles) return `Como mucho ${va.maxfiles} archivos`;
        return '';
    }
    return '';
  }

  /**
   * new PTForm(el, opts)
   * opts: schema, theme, cats, members, people, priorities, answers, title, uploader(file, onProgress) → Promise,
   *       onChange(), showEstimate (bool), unit, readonly, device ('desktop'|'mobile'), titleField (bool)
   */
  class PTForm {
    constructor(el, o) {
      this.el = el; this.o = o; this.s = o.schema;
      this.ans = Object.assign({}, o.answers || {});
      this.title = o.title || '';
      this.err = {}; this.touched = {};
      // valores por defecto
      for (const { it } of items(this.s)) if (VAL_TYPES.has(it.type) && this.ans[it.id] === undefined && it.default !== undefined && it.default !== '' && it.type !== 'hidden') this.ans[it.id] = it.default;
      el.classList.add('ptf');
      this.applyTheme();
    }
    applyTheme() {
      const t = this.o.theme || {}, st = this.el.style;
      if (t.accent) st.setProperty('--ptf-accent', t.accent); else st.removeProperty('--ptf-accent');
      if (t.bg) st.setProperty('--ptf-bg', t.bg); else st.removeProperty('--ptf-bg');
      if (t.card) st.setProperty('--ptf-card', t.card); else st.removeProperty('--ptf-card');
      if (t.text) st.setProperty('--ptf-text', t.text); else st.removeProperty('--ptf-text');
      st.setProperty('--ptf-radius', (t.radius ?? 12) + 'px');
      st.setProperty('--ptf-gap', (t.gap ?? 16) + 'px');
      const fonts = { Montserrat: "'Montserrat',sans-serif", Inter: "'Inter',sans-serif", Poppins: "'Poppins',sans-serif", Roboto: "'Roboto',sans-serif", Lora: "'Lora',serif", system: 'system-ui,sans-serif' };
      if (t.font && fonts[t.font]) st.setProperty('--ptf-font', fonts[t.font]); else st.removeProperty('--ptf-font');
      this.el.dataset.btn = t.btn || 'solid';
      this.el.dataset.bg = t.bg_style || 'plain';
    }
    vis() { return visible(this.s, this.ans); }
    /** Valores limpios para mandar (solo lo visible; los adjuntos como {token, name}). */
    values() {
      const v = this.vis(), out = {};
      for (const { it } of items(this.s)) {
        if (!VAL_TYPES.has(it.type) || !v[it.id] || this.ans[it.id] === undefined) continue;
        const x = this.ans[it.id];
        out[it.id] = (it.type === 'file' || it.type === 'image') ? (x || []).filter(f => f.token && !f.uploading).map(f => ({ token: f.token, name: f.name })) : x;
      }
      return out;
    }
    /** Errores de una sección (o de todo con null). Marca como tocados los campos para mostrarlos. */
    validate(secIdx = null) {
      const v = this.vis(), errs = {};
      const secs = secIdx === null ? this.s.sections : [this.s.sections[secIdx]];
      for (const sec of secs) for (const { it } of items({ sections: [sec] })) {
        if (!VAL_TYPES.has(it.type) || it.type === 'hidden' || !v[it.id]) continue;
        const e = check(it, this.ans[it.id], this.o);
        if (e) errs[it.id] = e;
        this.touched[it.id] = true;
      }
      if (this.o.titleField !== false && (secIdx === null || secIdx === 0) && !this.title.trim()) { errs.__title = 'Poné un título para tu pedido'; this.touched.__title = true; }
      this.err = Object.assign({}, this.err, errs);
      for (const k in this.err) if (!errs[k] && (secIdx === null || secs.some(s => items({ sections: [s] }).some(x => x.it.id === k)))) delete this.err[k];
      this.paintErrors();
      return errs;
    }
    setErrors(map) { this.err = Object.assign({}, map); for (const k in map) this.touched[k] = true; this.paintErrors(); }
    paintErrors() {
      this.el.querySelectorAll('[data-fid]').forEach(f => {
        const id = f.dataset.fid, e = this.touched[id] ? this.err[id] : '';
        f.classList.toggle('bad', !!e);
        const m = f.querySelector(':scope > .ptf-err'); if (m) m.textContent = e || '';
      });
    }
    /** Primer campo con error (para llevar ahí la vista). */
    firstError() { return this.el.querySelector('[data-fid].bad'); }

    // ── Dibujo ──
    render(secIdx = null, extraTop = '') {
      const secs = secIdx === null ? this.s.sections.map((s, i) => [s, i]) : [[this.s.sections[secIdx], secIdx]];
      const v = this.vis();
      let h = extraTop;
      if (this.o.titleField !== false && (secIdx === null || secIdx === 0)) {
        h += `<div class="ptf-grid"><div class="ptf-f w12" data-fid="__title"><label class="ptf-l" for="ptf___title">${esc(this.s.title_label || 'Título del pedido')} <b class="req">*</b></label>
          <input class="ptf-in ptf-title" id="ptf___title" maxlength="200" placeholder="${esc(this.s.title_ph || 'Ej.: Placas para la promo de verano')}" value="${esc(this.title)}"${this.o.readonly ? ' disabled' : ''}><div class="ptf-err" role="alert"></div></div></div>`;
      }
      for (const [sec, i] of secs) {
        const shown = !sec.cond || cond(sec.cond, this.ans);
        h += `<section class="ptf-sec" data-sec="${i}"${shown ? '' : ' hidden'}>`;
        if (secIdx === null && (sec.title || sec.desc)) h += `<header class="ptf-sec-h"><span class="ptf-sec-ic"><i class="fa-solid ${esc(sec.icon || 'fa-circle-dot')}"></i></span><div><h3>${esc(sec.title)}</h3>${sec.desc ? `<p>${esc(sec.desc)}</p>` : ''}</div></header>`;
        h += `<div class="ptf-grid">${(sec.items || []).map(it => this.item(it, v)).join('')}</div></section>`;
      }
      this.el.innerHTML = h;
      this.bind();
      this.paintErrors();
    }
    item(it, v) {
      const w = COLS[it.width] || 12, hid = v[it.id] ? '' : ' hidden';
      const req = it.required ? ' <b class="req">*</b>' : '';
      const dis = this.o.readonly ? ' disabled' : '';
      const id = 'ptf_' + it.id, val = this.ans[it.id];
      const lab = (it.label ? `<label class="ptf-l" for="${id}">${esc(it.label)}${req}</label>` : '') + (it.desc && !['paragraph', 'info', 'heading'].includes(it.type) ? `<div class="ptf-d">${esc(it.desc)}</div>` : '');
      const wrap = (inner, cls = '') => `<div class="ptf-f w${w} t-${it.type} ${cls}" data-fid="${esc(it.id)}"${hid}>${inner}<div class="ptf-err" role="alert"></div></div>`;
      const ph = it.placeholder ? ` placeholder="${esc(it.placeholder)}"` : '';
      const va = it.valid || {};
      switch (it.type) {
        case 'hidden': return this.o.agentView ? wrap(`${lab}<div class="ptf-hidden"><i class="fa-solid fa-eye-slash"></i> ${esc(it.default || '—')} <small>(oculto para quien pide)</small></div>`) : '';
        case 'separator': return `<div class="ptf-f w12 t-sep" data-fid="${esc(it.id)}"${hid}><hr></div>`;
        case 'heading': return `<div class="ptf-f w${w} t-heading" data-fid="${esc(it.id)}"${hid}><h4>${esc(it.label)}</h4>${it.desc ? `<p class="ptf-d">${esc(it.desc)}</p>` : ''}</div>`;
        case 'paragraph': return `<div class="ptf-f w${w} t-paragraph" data-fid="${esc(it.id)}"${hid}><p>${esc(it.desc || it.label).replace(/\n/g, '<br>')}</p></div>`;
        case 'info': return `<div class="ptf-f w${w} t-info tone-${esc(it.tone || 'info')}" data-fid="${esc(it.id)}"${hid}><i class="fa-solid ${{ info: 'fa-circle-info', ok: 'fa-circle-check', warn: 'fa-triangle-exclamation', danger: 'fa-circle-exclamation' }[it.tone || 'info']}"></i><div>${it.label ? `<b>${esc(it.label)}</b>` : ''}${it.desc ? `<p>${esc(it.desc).replace(/\n/g, '<br>')}</p>` : ''}</div></div>`;
        case 'group': return `<div class="ptf-f w${w} t-group" data-fid="${esc(it.id)}"${hid}><fieldset class="ptf-group">${it.label ? `<legend>${esc(it.label)}</legend>` : ''}${it.desc ? `<p class="ptf-d">${esc(it.desc)}</p>` : ''}<div class="ptf-grid">${(it.items || []).map(x => this.item(x, v)).join('')}</div></fieldset></div>`;
        case 'textarea': return wrap(`${lab}<textarea class="ptf-in" id="${id}" rows="4"${ph}${va.maxlen ? ` maxlength="${va.maxlen}"` : ''}${dis}>${esc(val ?? '')}</textarea>${va.maxlen ? `<div class="ptf-count"><span>${String(val ?? '').length}</span>/${va.maxlen}</div>` : ''}`);
        case 'number': return wrap(`${lab}<input class="ptf-in" id="${id}" type="number" inputmode="decimal"${va.min !== undefined ? ` min="${va.min}"` : ''}${va.max !== undefined ? ` max="${va.max}"` : ''}${va.step ? ` step="${va.step}"` : ' step="any"'} value="${esc(val ?? '')}"${ph}${dis}>`);
        case 'date': return wrap(`${lab}<input class="ptf-in" id="${id}" type="date"${va.mindate ? ` min="${dateLimit(va.mindate)}"` : ''}${va.maxdate ? ` max="${dateLimit(va.maxdate)}"` : ''} value="${esc(val ?? '')}"${dis}>`);
        case 'time': return wrap(`${lab}<input class="ptf-in" id="${id}" type="time" value="${esc(val ?? '')}"${dis}>`);
        case 'color': return wrap(`${lab}<div class="ptf-color"><input type="color" id="${id}" value="${esc(val || '#ff6600')}"${dis}><input class="ptf-in" data-hex maxlength="7" value="${esc(val || '')}" placeholder="#ff6600"${dis}></div>`);
        case 'select': case 'person': case 'approval': {
          if (it.type === 'approval' && !it.pick) {
            const who = (this.o.members || []).find(m => m.id === it.approver);
            return wrap(`${lab}<div class="ptf-approval"><i class="fa-solid fa-stamp"></i><div><b>Necesita aprobación</b><span>${who ? 'La aprueba ' + esc(who.name) : 'La aprueba un responsable del departamento'}</span></div></div>`);
          }
          const opts = it.type === 'select' ? it.options.map(o => [o.v, o.l]) : (it.type === 'approval' ? (this.o.members || []) : (this.o.people || [])).map(p => [String(p.id), p.name]);
          return wrap(`${lab}<div class="ptf-sel"><select class="ptf-in" id="${id}"${dis}><option value="">${esc(it.placeholder || 'Elegí…')}</option>${opts.map(([k, l]) => `<option value="${esc(k)}"${String(val ?? '') === String(k) ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select><i class="fa-solid fa-chevron-down"></i></div>`);
        }
        case 'radio': return wrap(`${lab}<div class="ptf-opts" role="radiogroup">${it.options.map(o => `<label class="ptf-opt${val === o.v ? ' on' : ''}"${o.color ? ` style="--oc:${o.color}"` : ''}><input type="radio" name="${id}" value="${esc(o.v)}"${val === o.v ? ' checked' : ''}${dis}>${o.icon ? `<i class="fa-solid ${esc(o.icon)}"></i>` : '<span class="dot"></span>'}<span>${esc(o.l)}</span>${this.o.showEstimate && o.est ? `<em>${o.est.op === 'mult' ? '×' + o.est.v : '+' + fmtMin(o.est.v)}</em>` : ''}</label>`).join('')}</div>`);
        case 'multiselect': return wrap(`${lab}<div class="ptf-opts multi">${it.options.map(o => { const on = (val || []).includes(o.v); return `<label class="ptf-opt${on ? ' on' : ''}"${o.color ? ` style="--oc:${o.color}"` : ''}><input type="checkbox" value="${esc(o.v)}"${on ? ' checked' : ''}${dis}>${o.icon ? `<i class="fa-solid ${esc(o.icon)}"></i>` : '<span class="box"><i class="fa-solid fa-check"></i></span>'}<span>${esc(o.l)}</span>${this.o.showEstimate && o.est ? `<em>${o.est.op === 'mult' ? '×' + o.est.v : '+' + fmtMin(o.est.v)}</em>` : ''}</label>`; }).join('')}</div>`);
        case 'checkbox': return wrap(`<label class="ptf-switch"><input type="checkbox" id="${id}"${val === '1' ? ' checked' : ''}${dis}><span class="tg"></span><span class="tx">${esc(it.label)}${req}${this.o.showEstimate && it.est ? ` <em>+${fmtMin(it.est.v)}</em>` : ''}</span></label>${it.desc ? `<div class="ptf-d">${esc(it.desc)}</div>` : ''}`);
        case 'priority': return wrap(`${lab}<div class="ptf-opts prio">${(this.o.priorities || []).map(p => `<label class="ptf-opt${val === p.id ? ' on' : ''}" style="--oc:${esc(p.color)}"><input type="radio" name="${id}" value="${esc(p.id)}"${val === p.id ? ' checked' : ''}${dis}><span class="dot"></span><span>${esc(p.name)}</span></label>`).join('')}</div>`);
        case 'catpick': {
          const list = (this.o.cats || []).filter(c => (c.parent_id || 0) === (it.root || 0) && c.active !== 0);
          const chosen = String(val ?? '');
          return wrap(`${lab}<div class="ptf-cats">${list.map(c => `<button type="button" class="ptf-cat${chosen === String(c.id) ? ' on' : ''}" data-cat="${c.id}" style="--cc:${esc(c.color || '#ff6600')}"${dis}>
              ${c.image ? `<span class="img" style="background-image:url('portal_file.php?img=${esc(c.image)}')"></span>` : `<span class="ic"><i class="fa-solid ${esc(c.icon || 'fa-shapes')}"></i></span>`}
              <b>${esc(c.name)}</b>${c.description ? `<small>${esc(c.description)}</small>` : ''}${this.o.showEstimate && c.base_min ? `<em><i class="fa-regular fa-clock"></i> ${fmtMin(c.base_min)}</em>` : ''}<i class="fa-solid fa-circle-check ok"></i></button>`).join('') || '<div class="ptf-d">Este departamento todavía no cargó opciones.</div>'}</div>`);
        }
        case 'file': case 'image': {
          const list = val || [];
          const accept = (va.ext || (it.type === 'image' ? ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'] : [])).map(e => '.' + e).join(',');
          return wrap(`${lab}<div class="ptf-drop${this.o.readonly ? ' ro' : ''}" tabindex="0" role="button" aria-label="Adjuntar archivos"><input type="file" multiple${accept ? ` accept="${accept}"` : ''} hidden${dis}>
              <i class="fa-solid ${it.type === 'image' ? 'fa-image' : 'fa-cloud-arrow-up'}"></i><b>Arrastrá ${it.type === 'image' ? 'imágenes' : 'archivos'} o tocá para elegir</b><span>${va.maxfiles ? 'Hasta ' + va.maxfiles + ' · ' : ''}${va.maxmb ? 'máx. ' + va.maxmb + ' MB c/u' : 'máx. 25 MB c/u'}</span></div>
            <ul class="ptf-files">${list.map((f, i) => `<li${f.uploading ? ' class="up"' : ''}><i class="fa-solid ${/\.(png|jpe?g|webp|gif|svg)$/i.test(f.name) ? 'fa-image' : 'fa-file'}"></i><span>${esc(f.name)}</span><small>${f.uploading ? Math.round(f.pct || 0) + ' %' : fmtSize(f.size || 0)}</small>${f.uploading ? `<b class="bar" style="--p:${f.pct || 0}%"></b>` : `<button type="button" data-rm="${i}" aria-label="Quitar"${dis}><i class="fa-solid fa-xmark"></i></button>`}</li>`).join('')}</ul>`);
        }
        default: {
          const type = { email: 'email', phone: 'tel', url: 'url' }[it.type] || 'text';
          return wrap(`${lab}<div class="ptf-inwrap">${{ email: '<i class="fa-regular fa-envelope"></i>', phone: '<i class="fa-solid fa-phone"></i>', url: '<i class="fa-solid fa-link"></i>' }[it.type] || ''}<input class="ptf-in" id="${id}" type="${type}"${ph}${va.maxlen ? ` maxlength="${va.maxlen}"` : ''} value="${esc(val ?? '')}"${dis}></div>`);
        }
      }
    }
    bind() {
      const el = this.el;
      const set = (id, v, rerender = false) => {
        if (v === '' || (Array.isArray(v) && !v.length)) delete this.ans[id]; else this.ans[id] = v;
        this.touched[id] = true;
        const it = items(this.s).find(x => x.it.id === id)?.it;
        if (it) { const e = check(it, v, this.o); if (e) this.err[id] = e; else delete this.err[id]; }
        this.refreshVisibility();
        this.paintErrors();
        if (rerender) this.redrawField(id);
        this.o.onChange && this.o.onChange();
      };
      el.querySelector('#ptf___title')?.addEventListener('input', e => { this.title = e.target.value; if (this.title.trim()) delete this.err.__title; this.touched.__title = true; this.paintErrors(); this.o.onChange && this.o.onChange(); });
      el.querySelectorAll('[data-fid]').forEach(f => {
        const id = f.dataset.fid;
        const it = items(this.s).find(x => x.it.id === id)?.it; if (!it) return;
        const inp = f.querySelector(':scope input.ptf-in, :scope textarea.ptf-in, :scope select.ptf-in, :scope .ptf-inwrap input, :scope .ptf-sel select');
        if (['text', 'textarea', 'number', 'email', 'phone', 'url', 'date', 'time'].includes(it.type) && inp) {
          inp.addEventListener('input', () => { set(id, inp.value); const c = f.querySelector('.ptf-count span'); if (c) c.textContent = inp.value.length; });
          inp.addEventListener('blur', () => { this.touched[id] = true; this.paintErrors(); });
        }
        if (['select', 'person', 'approval'].includes(it.type) && inp) inp.addEventListener('change', () => set(id, inp.value));
        if (it.type === 'color') {
          const c = f.querySelector('input[type=color]'), hx = f.querySelector('[data-hex]');
          c?.addEventListener('input', () => { hx.value = c.value; set(id, c.value); });
          hx?.addEventListener('input', () => { if (/^#[0-9a-f]{6}$/i.test(hx.value)) { c.value = hx.value; set(id, hx.value.toLowerCase()); } else if (!hx.value) set(id, ''); });
        }
        if (it.type === 'checkbox') f.querySelector('input')?.addEventListener('change', e => set(id, e.target.checked ? '1' : ''));
        if (['radio', 'priority'].includes(it.type)) f.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', () => { f.querySelectorAll('.ptf-opt').forEach(o => o.classList.toggle('on', o.contains(r) && r.checked)); set(id, r.value); }));
        if (it.type === 'multiselect') f.querySelectorAll('input[type=checkbox]').forEach(r => r.addEventListener('change', () => { r.closest('.ptf-opt').classList.toggle('on', r.checked); set(id, [...f.querySelectorAll('input:checked')].map(x => x.value)); }));
        if (it.type === 'catpick') f.querySelectorAll('[data-cat]').forEach(b => b.addEventListener('click', () => {
          const same = String(this.ans[id] ?? '') === b.dataset.cat;
          f.querySelectorAll('[data-cat]').forEach(x => x.classList.toggle('on', !same && x === b));
          b.animate?.([{ transform: 'scale(.97)' }, { transform: 'scale(1)' }], { duration: 160 });
          set(id, same ? '' : b.dataset.cat);
        }));
        if (it.type === 'file' || it.type === 'image') this.bindFiles(f, it, set);
      });
    }
    bindFiles(f, it, set) {
      const drop = f.querySelector('.ptf-drop'), inp = f.querySelector('input[type=file]');
      if (!drop || this.o.readonly) return;
      const add = files => {
        const list = this.ans[it.id] ? [...this.ans[it.id]] : [];
        for (const file of files) {
          const max = (it.valid?.maxfiles) || 10;
          if (list.length >= max) { this.err[it.id] = `Como mucho ${max} archivos`; this.touched[it.id] = true; this.paintErrors(); break; }
          const mb = it.valid?.maxmb || 25;
          if (file.size > mb * 1048576) { this.err[it.id] = `"${file.name}" pesa más de ${mb} MB`; this.touched[it.id] = true; this.paintErrors(); continue; }
          const rec = { name: file.name, size: file.size, uploading: true, pct: 0 };
          list.push(rec);
          if (!this.o.uploader) { rec.uploading = false; rec.token = 'preview'; continue; }
          this.o.uploader(file, p => { rec.pct = p; this.redrawField(it.id); })
            .then(r => { Object.assign(rec, r, { uploading: false }); set(it.id, this.ans[it.id] || [], true); })
            .catch(e => { this.ans[it.id] = (this.ans[it.id] || []).filter(x => x !== rec); this.err[it.id] = e.message; this.touched[it.id] = true; this.redrawField(it.id); this.paintErrors(); });
        }
        set(it.id, list, true);
      };
      drop.addEventListener('click', () => inp.click());
      drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
      inp.addEventListener('change', () => { add([...inp.files]); inp.value = ''; });
      drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
      drop.addEventListener('dragleave', () => drop.classList.remove('over'));
      drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); add([...e.dataTransfer.files]); });
      f.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', () => { const l = [...(this.ans[it.id] || [])]; l.splice(+b.dataset.rm, 1); set(it.id, l, true); }));
    }
    /** Vuelve a dibujar un solo campo (adjuntos que avanzan) sin perder el foco del resto. */
    redrawField(id) {
      const f = this.el.querySelector(`[data-fid="${CSS.escape(id)}"]`); if (!f) return;
      const it = items(this.s).find(x => x.it.id === id)?.it; if (!it) return;
      const tmp = document.createElement('div'); tmp.innerHTML = this.item(it, this.vis());
      const nf = tmp.firstElementChild; if (!nf) return;
      f.replaceWith(nf);
      const set = (fid, v, rr) => { if (v === '' || (Array.isArray(v) && !v.length)) delete this.ans[fid]; else this.ans[fid] = v; this.refreshVisibility(); if (rr) this.redrawField(fid); this.o.onChange && this.o.onChange(); };
      if (it.type === 'file' || it.type === 'image') this.bindFiles(nf, it, set);
      this.paintErrors();
    }
    /** Muestra u oculta campos y secciones según las condiciones, con una transición suave. */
    refreshVisibility() {
      const v = this.vis();
      this.el.querySelectorAll('[data-fid]').forEach(f => {
        const id = f.dataset.fid; if (id === '__title') return;
        const show = !!v[id];
        if (show && f.hidden) { f.hidden = false; f.animate?.([{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: 'ease-out' }); }
        else if (!show && !f.hidden) f.hidden = true;
      });
      this.el.querySelectorAll('.ptf-sec[data-sec]').forEach(s => { const sec = this.s.sections[+s.dataset.sec]; s.hidden = !!(sec && sec.cond && !cond(sec.cond, this.ans)); });
    }
    /** Secciones que se muestran (las que tienen condición falsa se saltean en el modo por pasos). */
    activeSections() { return this.s.sections.map((s, i) => i).filter(i => !this.s.sections[i].cond || cond(this.s.sections[i].cond, this.ans)); }
    /** Resumen legible de las respuestas (paso final). */
    summary() {
      const v = this.vis(), out = [];
      for (const sec of this.s.sections) {
        if (sec.cond && !cond(sec.cond, this.ans)) continue;
        const rows = [];
        for (const { it } of items({ sections: [sec] })) {
          if (!VAL_TYPES.has(it.type) || it.type === 'hidden' || !v[it.id]) continue;
          const x = this.ans[it.id]; if (x === undefined || x === '' || (Array.isArray(x) && !x.length)) continue;
          let t = x;
          if (it.type === 'checkbox') t = 'Sí';
          else if (it.type === 'select' || it.type === 'radio') t = (it.options.find(o => o.v === x) || {}).l || x;
          else if (it.type === 'multiselect') t = x.map(k => (it.options.find(o => o.v === k) || {}).l || k).join(', ');
          else if (it.type === 'catpick') t = ((this.o.cats || []).find(c => String(c.id) === String(x)) || {}).name || x;
          else if (it.type === 'priority') t = ((this.o.priorities || []).find(p => p.id === x) || {}).name || x;
          else if (it.type === 'person' || it.type === 'approval') t = ([...(this.o.people || []), ...(this.o.members || [])].find(p => String(p.id) === String(x)) || {}).name || x;
          else if (it.type === 'file' || it.type === 'image') t = x.map(f => f.name).join(', ');
          rows.push([it.label || '', t, it.type === 'color' ? x : null]);
        }
        if (rows.length) out.push({ title: sec.title, rows });
      }
      return out;
    }
  }

  window.PTForm = PTForm;
  window.PTCond = { cond, cmp, items, visible, check };
  window.PTFmt = { fmtMin, fmtSize, esc };
})();
