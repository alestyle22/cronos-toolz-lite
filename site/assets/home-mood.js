/* assets/home-mood.js — "¿Cómo estás hoy?" del Inicio: botón en el saludo (#d3-md-btn) que abre un modal (#d3-md-dlg).
   Una respuesta cada 24 horas (lo valida mood_api.php / mood_lib.php). Cada persona ve solo lo suyo; los admins ven
   además el promedio anónimo del equipo (días con 3 respuestas o más). */
(function () {
  var btn = document.getElementById('d3-md-btn'), dlg = document.getElementById('d3-md-dlg'), el = document.getElementById('d3-mood');
  if (!btn || !dlg || !el) return;
  var state, cfg;
  try { state = JSON.parse(el.dataset.state || '{}'); cfg = JSON.parse(el.dataset.cfg || '{}'); } catch (e) { return; }
  var L = cfg.levels || {}, R = cfg.reasons || {};
  var DAYS = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
  var MSG = { 1: 'Gracias por contarlo. Si necesitas algo, tu equipo está cerca.', 2: 'Ánimo: un mal día no es una mala semana.',
    3: 'Un día normal también está bien.', 4: '¡Qué bueno! Que siga así.', 5: '¡Excelente! Contagia esa energía.' };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var dayLetter = function (d) { return DAYS[new Date(d + 'T12:00:00').getDay()]; };
  var levelOf = function (avg) { return avg == null ? null : Math.max(1, Math.min(5, Math.round(avg))); };
  var face = function (lv) { var l = L[lv]; return l ? '<i class="fa-solid ' + esc(l.icon) + '"></i>' : ''; };
  var num = function (v) { return String(v).replace('.', ','); };
  var pick = { mood: null, reasons: [] }, busy = false, error = '';

  function chips(list) {
    return (list || []).map(function (k) { return R[k] ? '<span class="d3-md-r on static"><i class="fa-solid ' + esc(R[k].icon) + '"></i>' + esc(R[k].label) + '</span>' : ''; }).join('');
  }

  function render() {
    var mine = state.mine || {}, cur = mine.current, h = '';
    if (mine.can) {
      h += '<div class="d3-md-q"><b>¿Cómo estás hoy' + (cfg.name ? ', ' + esc(cfg.name) : '') + '?</b><small>Elige la carita que más se parezca a tu día.</small></div>'
        + '<div class="d3-md-faces" role="radiogroup" aria-label="Cómo estás hoy">';
      Object.keys(L).forEach(function (k) { var l = L[k], on = String(pick.mood) === k;
        h += '<button type="button" role="radio" aria-checked="' + on + '" class="d3-md-f' + (on ? ' on' : '') + (pick.mood && !on ? ' dim' : '') + '" data-mood="' + k + '" style="--m:' + esc(l.color) + '">' + face(k) + '<span>' + esc(l.label) + '</span></button>'; });
      h += '</div>';
      if (pick.mood) {
        h += '<div class="d3-md-rs"><small>¿Por qué? <em>(opcional, hasta 3)</em></small><div>';
        Object.keys(R).forEach(function (k) { var on = pick.reasons.indexOf(k) >= 0;
          h += '<button type="button" class="d3-md-r' + (on ? ' on' : '') + '" data-reason="' + esc(k) + '" aria-pressed="' + on + '"><i class="fa-solid ' + esc(R[k].icon) + '"></i>' + esc(R[k].label) + '</button>'; });
        h += '</div></div>';
      }
      if (error) h += '<div class="d3-md-err"><i class="fa-solid fa-circle-exclamation"></i> ' + esc(error) + '</div>';
      h += '<div class="d3-md-send"><button type="button" class="d3-md-go" data-send' + (pick.mood && !busy ? '' : ' disabled') + '>' + (busy ? '<i class="fa-solid fa-spinner fa-spin"></i> Enviando…' : '<i class="fa-solid fa-paper-plane"></i> Enviar') + '</button>'
        + '<small>Puedes responder una vez cada 24 horas.</small></div>';
    } else if (cur) {
      var l = L[cur.mood];
      h += '<div class="d3-md-done" style="--m:' + esc(l.color) + '">' + face(cur.mood) + '<div><b>' + esc(MSG[cur.mood]) + '</b><small>Hoy marcaste <strong>' + esc(l.label) + '</strong>.'
        + ' Puedes volver a responder en ' + (mine.wait_h === 1 ? '1 hora' : mine.wait_h + ' horas') + '.</small>'
        + (cur.reasons && cur.reasons.length ? '<div class="d3-md-rsv">' + chips(cur.reasons) + '</div>' : '') + '</div></div>';
    }
    h += '<div class="d3-md-week"><small>Tu semana</small><div>';
    (mine.week || []).forEach(function (d) { var l = d.mood ? L[d.mood] : null;
      h += '<span class="d3-md-d' + (l ? '' : ' empty') + '" style="' + (l ? '--m:' + esc(l.color) : '') + '" title="' + esc(d.day) + (l ? ' · ' + esc(l.label) : ' · sin respuesta') + '">' + (l ? face(d.mood) : '') + '<em>' + dayLetter(d.day) + '</em></span>'; });
    h += '</div></div>';
    if (state.team) {
      var t = state.team, lv = levelOf(t.today ? t.today.avg : null), wl = levelOf(t.avg);
      h += '<div class="d3-md-team"><small><i class="fa-solid fa-user-secret"></i> El equipo · promedio anónimo (solo admins)</small>';
      if (lv) h += '<div class="d3-md-tnow" style="--m:' + esc(L[lv].color) + '">' + face(lv) + '<span><b>' + esc(L[lv].label) + '</b> hoy · ' + num(t.today.avg) + ' de 5</span></div>';
      else h += '<div class="d3-md-tnow empty"><i class="fa-regular fa-hourglass-half"></i><span>Hoy todavía hay pocas respuestas. Se muestra desde ' + (t.min || 3) + '.</span></div>';
      h += '<div class="d3-md-bars">';
      (t.week || []).forEach(function (d) { var l = levelOf(d.avg);
        h += '<span title="' + esc(d.day) + (d.avg != null ? ' · ' + num(d.avg) + ' de 5 (' + d.n + ' respuestas)' : ' · pocas respuestas') + '"><i style="height:' + (d.avg != null ? Math.round(d.avg / 5 * 100) : 8) + '%;' + (l ? 'background:' + esc(L[l].color) : '') + '"' + (l ? '' : ' class="none"') + '></i><em>' + dayLetter(d.day) + '</em></span>'; });
      h += '</div>';
      var keys = Object.keys(t.reasons || {});
      if (keys.length) h += '<div class="d3-md-tr">' + keys.map(function (k) { return R[k] ? '<span><i class="fa-solid ' + esc(R[k].icon) + '"></i>' + esc(R[k].label) + ' <b>' + t.reasons[k] + '</b></span>' : ''; }).join('') + '</div>';
      if (wl) h += '<small class="d3-md-avg">Semana: ' + face(wl) + ' ' + num(t.avg) + ' de 5</small>';
      if (cfg.stats) h += '<a class="d3-md-more" href="' + esc(cfg.stats) + '"><i class="fa-solid fa-chart-line"></i> Ver más en Estadísticas › Bienestar</a>';
      h += '</div>';
    }
    el.innerHTML = h;
  }

  function paintBtn() {
    var mine = state.mine || {}, cur = mine.current, l = cur ? L[cur.mood] : null;
    btn.classList.toggle('beat', !!mine.can); btn.classList.toggle('done', !mine.can);
    if (l) btn.style.setProperty('--m', l.color);
    btn.innerHTML = '<i class="fa-solid ' + esc(l ? l.icon : 'fa-heart') + '"></i><span>' + (l ? 'Hoy: ' + esc(l.label) : '¿Cómo estás hoy?') + '</span>';
    btn.title = mine.can ? 'Cuéntanos cómo estás hoy' : 'Ya respondiste: puedes volver a hacerlo en ' + mine.wait_h + ' h';
  }

  function send() {
    if (busy || !pick.mood) return; busy = true; error = ''; render();
    fetch('/toolz/mood_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'set', mood: pick.mood, reasons: pick.reasons }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.mine) { state = d; pick = { mood: null, reasons: [] }; paintBtn(); } else error = (d && d.error) || 'No se pudo guardar. Prueba de nuevo.'; })
      .catch(function () { error = 'No se pudo guardar. Revisa la conexión.'; })
      .then(function () { busy = false; render(); var f = el.querySelector('.d3-md-done > i'); if (f) f.classList.add('pop'); });
  }

  btn.addEventListener('click', function () { error = ''; render(); if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); });
  dlg.addEventListener('click', function (e) {
    if (e.target === dlg || e.target.closest('[data-x]')) { dlg.close ? dlg.close() : dlg.removeAttribute('open'); return; }
    var f = e.target.closest('[data-mood]'), r = e.target.closest('[data-reason]');
    if (f) { pick.mood = +f.dataset.mood; render(); var b = el.querySelector('[data-mood="' + pick.mood + '"]'); if (b) b.classList.add('pop'); }
    else if (r) { var k = r.dataset.reason, i = pick.reasons.indexOf(k); if (i >= 0) pick.reasons.splice(i, 1); else if (pick.reasons.length < 3) pick.reasons.push(k); render(); }
    else if (e.target.closest('[data-send]')) send();
  });
  paintBtn();
})();
