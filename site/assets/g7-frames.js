/* assets/g7-frames.js — Marco de la foto de perfil en todo Cronos (avatar_frame_lib.php, g7-frames.css).
 * topbar.php deja en #g7-frames-data los marcos de todos: {me, f: {user_id: {type, color, color2}}}.
 * Se reconoce de quién es cada foto así:
 *   - <img> con un archivo de avatars/ (avatar_<id>_…): el id está en el nombre;
 *   - cualquier elemento con data-g7-uid="<id>" (también las iniciales, cuando no hay foto).
 * Fotos de menos de 18 px no llevan marco (no se vería). Sigue la página con un MutationObserver.
 * Para elegirlo: G7Frames.picker(contenedor, marco actual, onChange) — Mi configuración y el onboarding.
 * Doc: toolz/MARCOS.md */
(function () {
  'use strict';
  if (window.G7Frames) return;
  let data = { me: 0, f: {}, b: {}, p: {} };
  try { const n = document.getElementById('g7-frames-data'); if (n) data = Object.assign(data, JSON.parse(n.textContent || '{}')); } catch (e) {}
  if (Array.isArray(data.b)) data.b = {};
  if (Array.isArray(data.f)) data.f = {};   // PHP manda [] cuando nadie eligió uno

  const TYPES = [['ring', 'Aro'], ['double', 'Doble aro'], ['dashed', 'Punteado'], ['gradient', 'Degradado'], ['neon', 'Neón'], ['glow', 'Luminoso'], ['gamer', 'Gamer'], ['minimal', 'Minimalista']];
  const PAIRS = [['#ff6600', '#7c3aed'], ['#ec4899', '#f97316'], ['#22d3ee', '#a855f7'], ['#22c55e', '#0ea5e9'], ['#fbbf24', '#ef4444'], ['#ffffff', '#94a3b8'], ['#ff2bd6', '#00e5ff'], ['#d4af37', '#7c2d12']];
  const CLS = TYPES.map(t => 'g7-fr-' + t[0]);
  const AV_RE = /\/avatars\/avatar_(\d+)_/;

  function uidOf(el) {
    if (el.dataset && el.dataset.g7Uid) return +el.dataset.g7Uid || 0;
    if (el.tagName === 'IMG') { const m = AV_RE.exec(el.getAttribute('src') || ''); if (m) return +m[1]; }
    return 0;
  }
  /** Pone (o saca, con null) un marco en un elemento */
  function paint(el, f) {
    el.classList.remove('g7-fr', ...CLS);
    if (!f || !f.type) { el.style.removeProperty('--fr-c'); el.style.removeProperty('--fr-c2'); el.style.removeProperty('--fr-w'); return; }
    const w = el.offsetWidth || parseFloat(el.style.width) || parseFloat(el.getAttribute('width')) || 32;
    if (!el.offsetWidth) later(el, f);   // oculta todavía (menú cerrado, pestaña sin abrir): se ajusta el grosor cuando aparece
    if (w < 18) return;
    el.style.setProperty('--fr-c', f.color || '#ff6600'); el.style.setProperty('--fr-c2', f.color2 || '#7c3aed');
    el.style.setProperty('--fr-w', Math.max(1.5, Math.min(6, w * .055)).toFixed(2) + 'px');
    el.classList.add('g7-fr', 'g7-fr-' + f.type);
  }
  // ── Insignias (estado o la elegida, presence_lib.php): un circulito arriba a la derecha de la foto ──
  const bOf = new WeakMap(), bro = window.ResizeObserver ? new ResizeObserver(es => es.forEach(e => { if (e.target.isConnected) badge(e.target); })) : null;
  const drop = el => { const x = bOf.get(el); if (x) { x.remove(); bOf.delete(el); } };
  function badge(el) {
    const u = uidOf(el), b = u && data.b[u];
    if (!b || el.closest('[data-g7-nobadge]')) { drop(el); return; }
    if (bro && !el.__g7bro) { el.__g7bro = 1; bro.observe(el); }
    const w = el.offsetWidth; if (w < 26 || !el.parentNode) { drop(el); return; }
    // Las iniciales la llevan adentro; una <img> no puede, va a su lado
    const inside = el.tagName !== 'IMG' && getComputedStyle(el).position !== 'static', host = inside ? el : el.parentElement;
    const cs = getComputedStyle(host); if (cs.overflowX !== 'visible' && cs.overflowX !== 'clip' && !inside && host.offsetWidth <= w + 4) { drop(el); return; }   // la recortaría
    if (cs.position === 'static') host.classList.add('g7-bdg-host');
    let x = bOf.get(el); if (!x || x.parentNode !== host) { if (x) x.remove(); x = document.createElement('span'); x.className = 'g7-bdg'; x.__el = el; x.setAttribute('role', 'img'); bOf.set(el, x); host.appendChild(x); }
    const s = Math.round(Math.max(13, Math.min(34, w * .36)));
    if (x.textContent !== b[0]) x.textContent = b[0];
    if (x.title !== b[1]) { x.title = b[1]; x.setAttribute('aria-label', b[1]); }
    x.style.cssText = `width:${s}px;height:${s}px;font-size:${Math.round(s * .62)}px;left:${Math.round((inside ? 0 : el.offsetLeft) + w - s * .72)}px;top:${Math.round((inside ? 0 : el.offsetTop) - s * .28)}px;${b[2] ? '--bc:' + b[2] : ''}`;
  }
  /** Insignias nuevas para toda la página: {user_id: [emoji, título, color]} */
  function badges(map) { data.b = map && !Array.isArray(map) ? map : {}; document.querySelectorAll('.g7-bdg').forEach(x => { if (!x.__el || !x.__el.isConnected) x.remove(); }); document.querySelectorAll(SEL).forEach(el => { if (!el.closest('[data-g7-noframe]')) badge(el); }); document.dispatchEvent(new Event('g7:badges')); }
  const waiting = new WeakSet();
  const ro = window.ResizeObserver ? new ResizeObserver(es => es.forEach(e => { const el = e.target; if (!el.offsetWidth) return; ro.unobserve(el); waiting.delete(el); const u = uidOf(el); if (u || el.classList.contains('g7-fr')) paint(el, u ? data.f[u] : el.__g7f); })) : null;
  function later(el, f) { el.__g7f = f; if (ro && !waiting.has(el)) { waiting.add(el); ro.observe(el); } }
  function apply(el) { badge(el); const u = uidOf(el); if (!u && el.classList.contains('g7-fr')) { paint(el, null); return; } if (u) { if (el.offsetWidth === 0 && el.tagName === 'IMG' && !el.complete) el.addEventListener('load', () => paint(el, data.f[u]), { once: true }); paint(el, data.f[u]); } }
  const SEL = 'img[src*="/avatars/avatar_"], [data-g7-uid]';
  function scan(root) {
    if (root.nodeType !== 1) return;
    if (root.matches(SEL) && !root.closest('[data-g7-noframe]')) apply(root);
    root.querySelectorAll(SEL).forEach(el => { if (!el.closest('[data-g7-noframe]')) apply(el); });
  }
  /** Cambia el marco de una persona en la página (al elegirlo, sin recargar) */
  function set(uid, f) { if (f && f.type) data.f[uid] = f; else delete data.f[uid]; scan(document.body); }

  /** Selector de marco. onChange(marco|null) en cada cambio; devuelve {get, set} */
  function picker(box, cur, onChange) {
    let f = cur && cur.type ? Object.assign({ color: '#ff6600', color2: '#7c3aed' }, cur) : null;
    const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    box.classList.add('g7fp'); box.setAttribute('data-g7-noframe', '');   // las muestras no llevan el marco de la persona
    function draw() {
      const c1 = f ? f.color : '#ff6600', c2 = f ? f.color2 : '#7c3aed';
      box.innerHTML = `<div class="g7fp-list" role="radiogroup" aria-label="Marco de la foto">
          <button type="button" class="g7fp-opt${f ? '' : ' on'}" data-t="" role="radio" aria-checked="${f ? 'false' : 'true'}"><span class="g7fp-dot"><i class="fa-solid fa-ban"></i></span>Sin marco</button>
          ${TYPES.map(([t, n]) => `<button type="button" class="g7fp-opt${f && f.type === t ? ' on' : ''}" data-t="${t}" role="radio" aria-checked="${f && f.type === t ? 'true' : 'false'}"><span class="g7fp-dot" data-s="${t}"></span>${esc(n)}</button>`).join('')}</div>
        <div class="g7fp-colors"${f ? '' : ' hidden'}>
          <label>Color <input type="color" data-c="color" value="${esc(c1)}"></label>
          <label>Segundo color <input type="color" data-c="color2" value="${esc(c2)}"></label>
          <span class="g7fp-sw" aria-label="Combinaciones">${PAIRS.map(([a, b]) => `<button type="button" data-a="${a}" data-b="${b}" style="--a:${a};--b:${b}" title="${a} + ${b}" aria-label="Combinación ${a} y ${b}"></button>`).join('')}</span></div>`;
      box.querySelectorAll('.g7fp-dot[data-s]').forEach(d => paint(d, { type: d.dataset.s, color: c1, color2: c2 }));
    }
    function changed(redraw) { if (redraw) draw(); else box.querySelectorAll('.g7fp-dot[data-s]').forEach(d => paint(d, { type: d.dataset.s, color: f.color, color2: f.color2 })); onChange && onChange(f ? Object.assign({}, f) : null); }
    box.addEventListener('click', e => {
      const o = e.target.closest('.g7fp-opt'); if (o) { const t = o.dataset.t; f = t ? Object.assign({ color: '#ff6600', color2: '#7c3aed' }, f || {}, { type: t }) : null; changed(true); box.querySelector('.g7fp-opt.on')?.focus(); return; }
      const s = e.target.closest('.g7fp-sw button'); if (s && f) { f.color = s.dataset.a; f.color2 = s.dataset.b; box.querySelector('[data-c=color]').value = f.color; box.querySelector('[data-c=color2]').value = f.color2; changed(false); }
    });
    box.addEventListener('input', e => { const k = e.target.dataset && e.target.dataset.c; if (k && f) { f[k] = e.target.value; changed(false); } });
    draw();
    return { get: () => (f ? Object.assign({}, f) : null), set: v => { f = v && v.type ? Object.assign({ color: '#ff6600', color2: '#7c3aed' }, v) : null; draw(); } };
  }

  window.G7Frames = { badges, badgeOf: id => data.b[id] || null, presence: data.p, me: data.me, frames: data.f, types: TYPES, paint, set, picker, scan: () => scan(document.body) };
  const start = () => {
    scan(document.body);
    new MutationObserver(ms => { for (const m of ms) { if (m.type === 'attributes') apply(m.target); else { m.addedNodes.forEach(scan); if (m.removedNodes.length && m.target.children) for (const x of [...m.target.children]) if (x.classList.contains('g7-bdg') && !(x.__el && x.__el.isConnected)) x.remove(); } } })
      .observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['src', 'data-g7-uid'] });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
