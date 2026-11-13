/* assets/calificar.js — Aprobar, pedir cambios y calificar un pedido (ENTREGAS_CALIFICACION.md). Lo usan Trabajo (detalle del ticket y
 * Mis solicitudes) y el Inicio. Habla con trabajo/api.php: ev_get, ev_save, ticket_update (cerrar / reabrir) y ticket_msg.
 *   G7Rate.open(id, {onDone})     calificar (o ver la calificación)
 *   G7Rate.approve(id, {onDone, onClose})  aprobar y cerrar, y después calificar (onClose: al cerrar ese diálogo)
 *   G7Rate.changes(id, {onDone})  pedir cambios: comentario y vuelve a En curso
 *   G7Rate.stars(n)               estrellas para mostrar
 */
(function () {
  'use strict';
  if (window.G7Rate) return;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var LABELS = ['', 'Muy mal', 'Mal', 'Bien', 'Muy bien', 'Excelente'];

  function api(action, data) {
    var b = { action: action }; for (var k in (data || {})) b[k] = data[k];
    return fetch('/toolz/trabajo/api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) })
      .then(function (r) { return r.json().catch(function () { return { error: 'El servidor no respondió bien' }; }); })
      .then(function (j) { if (j.error) throw new Error(j.error); return j; });
  }

  var css = '.g7r-dlg{margin:auto;inset:0;max-height:calc(100vh - 32px);overflow:auto;border:1px solid var(--g7-border-3,#333);border-radius:16px;background:var(--g7-bg-2,#141416);color:var(--g7-text,#eee);padding:0;width:min(440px,calc(100vw - 32px));box-shadow:0 24px 60px rgba(0,0,0,.45)}'
    + '.g7r-dlg::backdrop{background:rgba(0,0,0,.55)}'
    + '.g7r-in{padding:22px 22px 18px;animation:g7rIn .2s ease-out}'
    + '.g7r-in h3{margin:0 0 4px;font-size:18px;font-weight:800}.g7r-sub{margin:0 0 16px;font-size:12px;color:var(--g7-text-4,#888)}'
    + '.g7r-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-top:1px solid var(--g7-border-2,#262626)}'
    + '.g7r-row:first-of-type{border-top:0}.g7r-row>span{font-size:13px;font-weight:600}.g7r-main>span{font-size:14px}'
    + '.g7r-st{display:inline-flex;gap:2px}.g7r-st button{border:0;background:none;padding:2px;cursor:pointer;font-size:22px;line-height:1;color:var(--g7-bg-5,#444);transition:transform .12s,color .12s}'
    + '.g7r-main .g7r-st button{font-size:28px}.g7r-st button.on{color:#f59e0b}.g7r-st button:hover{transform:scale(1.12)}'
    + '.g7r-st button:focus-visible{outline:2px solid var(--g7-accent);border-radius:4px}'
    + '.g7r-lbl{display:block;text-align:right;font-size:11px;color:var(--g7-text-4,#888);min-height:14px}'
    + '.g7r-ta{width:100%;margin-top:12px;min-height:70px;resize:vertical;background:var(--g7-bg-4,#1e1e21);border:1px solid var(--g7-border-3,#333);color:inherit;border-radius:10px;padding:9px 11px;font:inherit;font-size:13px;box-sizing:border-box}'
    + '.g7r-err{color:#ef4444;font-size:12px;min-height:16px;margin-top:8px}'
    + '.g7r-act{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}'
    + '.g7r-btn{border:1px solid var(--g7-border-3,#333);background:var(--g7-bg-4,#1e1e21);color:var(--g7-text-2,#ccc);border-radius:9px;padding:8px 14px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}'
    + '.g7r-btn.pri{background:var(--g7-accent);border-color:var(--g7-accent);color:#000}.g7r-btn:disabled{opacity:.5;cursor:not-allowed}'
    + '.g7r-stars{color:#f59e0b;letter-spacing:1px;white-space:nowrap}.g7r-stars .off{color:var(--g7-bg-5,#555)}'
    + '.g7r-ro .g7r-row>b{font-size:13px}.g7r-cm{margin-top:10px;font-size:13px;color:var(--g7-text-2,#ccc);background:var(--g7-bg-3,#1a1a1d);border-radius:10px;padding:10px 12px;white-space:pre-wrap}'
    + '@keyframes g7rIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}'
    + '@media (prefers-reduced-motion:reduce){.g7r-in{animation:none}.g7r-st button{transition:none}}';
  function ensureCss() { if (document.getElementById('g7r-css')) return; var s = document.createElement('style'); s.id = 'g7r-css'; s.textContent = css; document.head.appendChild(s); }

  function dlg(html) {
    ensureCss();
    var d = document.createElement('dialog'); d.className = 'g7r-dlg';
    d.innerHTML = '<div class="g7r-in">' + html + '</div>';
    document.body.appendChild(d);
    // Un solo camino para cerrar (botones, clic afuera, Esc): no depende del evento "close", que puede demorarse con la pestaña en segundo plano
    var hooks = [], shut = false;
    d._onShut = function (fn) { hooks.push(fn); };
    d._shut = function () { if (shut) return; shut = true; try { if (d.open) HTMLDialogElement.prototype.close.call(d); } catch (e) {} d.remove(); hooks.forEach(function (fn) { try { fn(); } catch (e) {} }); };
    d.addEventListener('close', d._shut);
    d.addEventListener('cancel', function (e) { e.preventDefault(); d._shut(); });
    d.addEventListener('click', function (e) { if (e.target === d) d._shut(); });
    d.showModal();
    return d;
  }
  function stars(n) {
    n = Math.max(0, Math.min(5, +n || 0));
    return '<span class="g7r-stars" role="img" aria-label="' + n + ' de 5 estrellas">' + '★'.repeat(n) + '<span class="off">' + '★'.repeat(5 - n) + '</span></span>';
  }
  /** Fila de estrellas para elegir (radio accesible con flechas). */
  function picker(key, label, main) {
    var b = ''; for (var i = 1; i <= 5; i++) b += '<button type="button" role="radio" aria-checked="false" aria-label="' + i + ' de 5" data-k="' + key + '" data-v="' + i + '">★</button>';
    return '<div class="g7r-row' + (main ? ' g7r-main' : '') + '"><span>' + esc(label) + '</span><div><div class="g7r-st" role="radiogroup" aria-label="' + esc(label) + '">' + b + '</div><small class="g7r-lbl" data-l="' + key + '"></small></div></div>';
  }

  function open(id, o) {
    o = o || {};
    return api('ev_get', { id: id }).then(function (r) {
      if (!r.can_rate) {
        if (!r.rating) { if (o.quiet) return; alert('Este pedido todavía no se puede calificar.'); return; }
        var rt = r.rating;
        dlg('<h3>Calificación</h3><p class="g7r-sub">' + esc(r.code + ' · ' + r.subject) + '</p><div class="g7r-ro">'
          + '<div class="g7r-row g7r-main"><span>General</span>' + stars(rt.stars) + '</div>'
          + rt.scores.map(function (s) { return '<div class="g7r-row"><span>' + esc(s.name) + '</span>' + stars(s.stars) + '</div>'; }).join('')
          + (rt.comment ? '<div class="g7r-cm">' + esc(rt.comment) + '</div>' : '') + '</div><div class="g7r-act"><button class="g7r-btn pri" type="button" data-x>Cerrar</button></div>')
          .querySelector('[data-x]').addEventListener('click', function (e) { e.target.closest('dialog')._shut(); });
        return;
      }
      var val = {};
      var d = dlg('<h3>¿Cómo te fue con este pedido?</h3><p class="g7r-sub">' + esc(r.code + ' · ' + r.subject) + '</p>'
        + picker('g', 'Calificación general', true) + r.criteria.map(function (c) { return picker('c' + c.id, c.name); }).join('')
        + '<textarea class="g7r-ta" maxlength="1000" placeholder="Comentario (opcional)"></textarea><div class="g7r-err" role="alert"></div>'
        + '<div class="g7r-act"><button class="g7r-btn" type="button" data-later>Más tarde</button><button class="g7r-btn pri" type="button" data-go disabled>Calificar</button></div>');
      if (o.onClose) d._onShut(o.onClose);
      var go = d.querySelector('[data-go]');
      var need = ['g'].concat(r.criteria.map(function (c) { return 'c' + c.id; }));
      var paint = function (k) {
        d.querySelectorAll('[data-k="' + k + '"]').forEach(function (b) { var on = +b.getAttribute('data-v') <= (val[k] || 0); b.classList.toggle('on', on); b.setAttribute('aria-checked', +b.getAttribute('data-v') === val[k] ? 'true' : 'false'); });
        var l = d.querySelector('[data-l="' + k + '"]'); if (l) l.textContent = LABELS[val[k] || 0];
        go.disabled = need.some(function (x) { return !val[x]; });
      };
      d.addEventListener('click', function (e) { var b = e.target.closest('[data-k]'); if (b) { val[b.getAttribute('data-k')] = +b.getAttribute('data-v'); paint(b.getAttribute('data-k')); } });
      d.addEventListener('keydown', function (e) {
        var b = e.target.closest && e.target.closest('[data-k]'); if (!b || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
        e.preventDefault(); var k = b.getAttribute('data-k'); val[k] = Math.max(1, Math.min(5, (val[k] || 0) + (e.key === 'ArrowRight' ? 1 : -1))); paint(k);
        d.querySelector('[data-k="' + k + '"][data-v="' + val[k] + '"]').focus();
      });
      d.querySelector('[data-later]').addEventListener('click', function () { d._shut(); });
      if (o.stars) { val.g = Math.max(1, Math.min(5, +o.stars)); paint('g'); }   // estrella elegida desde el Inicio
      go.addEventListener('click', function () {
        var scores = {}; r.criteria.forEach(function (c) { scores[c.id] = val['c' + c.id]; });
        go.disabled = true;
        api('ev_save', { id: id, stars: val.g, scores: scores, comment: d.querySelector('.g7r-ta').value })
          .then(function (x) { d._shut(); if (o.onDone) o.onDone(x.rating); })
          .catch(function (e) { d.querySelector('.g7r-err').textContent = e.message; go.disabled = false; });
      });
    }).catch(function (e) { alert(e.message); });
  }

  function approve(id, o) {
    o = o || {};
    return api('ticket_update', { id: id, status: 'cerrado' }).then(function () {
      if (o.onClosed) o.onClosed();
      return open(id, { quiet: true, onDone: o.onDone, onClose: o.onClose });
    }).catch(function (e) { alert(e.message); });
  }

  function changes(id, o) {
    o = o || {};
    var d = dlg('<h3>Pedir cambios</h3><p class="g7r-sub">Cuenta qué falta o qué hay que corregir. El pedido vuelve a En curso.</p>'
      + '<textarea class="g7r-ta" maxlength="5000" placeholder="Ej: falta la versión para celular" style="margin-top:0"></textarea><div class="g7r-err" role="alert"></div>'
      + '<div class="g7r-act"><button class="g7r-btn" type="button" data-x>Cancelar</button><button class="g7r-btn pri" type="button" data-go>Enviar</button></div>');
    var ta = d.querySelector('textarea'); setTimeout(function () { ta.focus(); }, 30);
    d.querySelector('[data-x]').addEventListener('click', function () { d._shut(); });
    d.querySelector('[data-go]').addEventListener('click', function (e) {
      var body = ta.value.trim(); if (!body) { d.querySelector('.g7r-err').textContent = 'Escribe qué hay que cambiar'; return; }
      e.target.disabled = true;
      api('ticket_msg', { id: id, body: body }).then(function () { return api('ticket_update', { id: id, status: 'en_curso' }); })
        .then(function () { d._shut(); if (o.onDone) o.onDone(); })
        .catch(function (x) { d.querySelector('.g7r-err').textContent = x.message; e.target.disabled = false; });
    });
  }

  window.G7Rate = { open: open, approve: approve, changes: changes, stars: stars };
})();
