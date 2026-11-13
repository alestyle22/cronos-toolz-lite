/* promos/entrega.js — "Entrega de un pedido" al cargar un contenido (ENTREGAS_CALIFICACION.md).
 * Busca los tickets y tareas abiertos que atiende la persona (trabajo/api.php deliver_targets). Al guardar, promos.js llama a
 * pdAppend(fd) para mandar el elegido y pdAfter(delivery) para avisar el resultado. La entrega la hace el servidor (wk_deliver). */
(function () {
  'use strict';
  var wrap = document.getElementById('pd-wrap'); if (!wrap) return;
  var $ = function (id) { return document.getElementById(id); };
  var q = $('pd-q'), res = $('pd-res'), sel = $('pd-sel'), kind = $('pd-kind'), id = $('pd-id');
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var items = [], active = 0, timer = 0, seq = 0;

  function api(action, data) {
    var b = { action: action }; for (var k in (data || {})) b[k] = data[k];
    return fetch('/toolz/trabajo/api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) })
      .then(function (r) { return r.json(); }).then(function (j) { if (j.error) throw new Error(j.error); return j; });
  }
  function search() {
    var my = ++seq;
    api('deliver_targets', { q: q.value.trim() }).then(function (d) {
      if (my !== seq) return;
      items = d.items || []; active = 0; draw();
    }).catch(function () { items = []; draw(); });
  }
  function draw() {
    res.hidden = false;
    res.innerHTML = items.length ? items.map(function (it, i) {
      return '<button type="button" role="option" data-i="' + i + '" class="' + (i === active ? 'on' : '') + '"><i class="fa-solid ' + (it.kind === 'ticket' ? 'fa-ticket' : 'fa-list-check') + '"></i>'
        + '<span><b>' + esc(it.code) + ' · ' + esc(it.title) + '</b><small>' + esc(it.sub) + '</small></span></button>';
    }).join('') : '<div class="pd-empty">No tienes tickets ni tareas abiertos con ese texto.</div>';
  }
  function pick(it) {
    kind.value = it.kind; id.value = it.id; res.hidden = true; q.value = ''; q.parentNode.hidden = true;
    sel.hidden = false;
    sel.innerHTML = '<i class="fa-solid ' + (it.kind === 'ticket' ? 'fa-ticket' : 'fa-list-check') + '"></i><span><b>' + esc(it.code) + ' · ' + esc(it.title) + '</b><small>'
      + (it.kind === 'ticket' ? 'Al guardar se responde con los enlaces y pasa a Resuelto' : 'Al guardar recibe los enlaces y pasa a terminada') + '</small></span>'
      + '<button type="button" class="pd-x" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button>';
    sel.querySelector('.pd-x').addEventListener('click', clear);
  }
  function clear() { kind.value = ''; id.value = ''; sel.hidden = true; sel.innerHTML = ''; q.parentNode.hidden = false; }

  q.addEventListener('focus', function () { if (!res.innerHTML) search(); else res.hidden = false; });
  q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 250); });
  q.addEventListener('keydown', function (e) {
    if (res.hidden || !items.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; draw(); }
    else if (e.key === 'Enter') { e.preventDefault(); pick(items[active]); }
    else if (e.key === 'Escape') res.hidden = true;
  });
  res.addEventListener('mousedown', function (e) { var b = e.target.closest('[data-i]'); if (b) { e.preventDefault(); pick(items[+b.getAttribute('data-i')]); } });
  document.addEventListener('click', function (e) { if (!e.target.closest('#pd-wrap')) res.hidden = true; });

  window.pdAppend = function (fd) { if (id.value) { fd.append('deliver_kind', kind.value); fd.append('deliver_id', id.value); } };
  window.pdAfter = function (d) {
    clear(); res.innerHTML = '';
    if (!d) return;
    var t = document.createElement('div');
    t.className = 'pd-toast' + (d.ok ? '' : ' err');
    t.innerHTML = d.ok ? '<i class="fa-solid fa-circle-check"></i> Entregado: ' + esc(d.code) + (d.kind === 'ticket' ? ' pasó a Resuelto. <a href="/toolz/trabajo/#tickets/' + (+d.id) + '">Ver ticket</a>' : ' quedó terminada.')
      : '<i class="fa-solid fa-circle-exclamation"></i> El contenido se guardó, pero no se pudo entregar: ' + esc(d.error);
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, d.ok ? 6000 : 9000);
  };
})();
