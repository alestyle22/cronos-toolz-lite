/* assets/user-tz.js — Mi perfil › Ubicación y zona horaria. Usa G7Time (assets/g7-time.js) y los datos de #tz-data
 * (países con sus zonas IANA, armados en el servidor con tz_lib.php). Guarda con user_config.php (action save_location). */
(function () {
  'use strict';
  var dataEl = document.getElementById('tz-data'); if (!dataEl || !window.G7Time) return;
  var T = window.G7Time, D = JSON.parse(dataEl.textContent || '{}');
  var countries = D.countries || {}, names = D.names || {};
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var norm = function (s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

  // Estado del formulario (lo guardado al abrir)
  var mine = D.mine || {};
  var S = { cc: mine.timezone ? (mine.country || '') : '', tz: mine.timezone || '' };
  $('tz-city').value = mine.city || '';
  $('tz-city-public').checked = !!(+mine.city_public);

  // Lista de países con nombre en español (Intl), ordenada
  var list = Object.keys(countries).map(function (cc) { return { cc: cc, name: T.countryName(cc), n: countries[cc].length }; })
    .sort(function (a, b) { return a.name.localeCompare(b.name, 'es'); });
  list.forEach(function (c) { c.key = norm(c.name + ' ' + c.cc); });

  var zoneLabel = function (tz) { return (names[tz] || tz) + ' · ' + T.offsetLabel(tz); };

  // ── Selector de país con buscador ──
  var btn = $('tz-country-btn'), pop = $('tz-pop'), search = $('tz-search'), ul = $('tz-list'), active = -1, shown = [];
  function renderList() {
    var q = norm(search.value.trim());
    shown = q ? list.filter(function (c) { return c.key.indexOf(q) !== -1; }) : list;
    if (!shown.length) { ul.innerHTML = '<li class="tz-empty">No hay países con ese nombre</li>'; active = -1; return; }
    ul.innerHTML = shown.map(function (c, i) {
      return '<li role="option" data-cc="' + c.cc + '" aria-selected="' + (c.cc === S.cc) + '"' + (i === active ? ' class="on"' : '') + '>' + T.flag(c.cc) + '<span>' + esc(c.name) + '</span>' + (c.n > 1 ? '<small>' + c.n + ' zonas</small>' : '') + '</li>';
    }).join('');
  }
  function openPop() {
    pop.hidden = false; btn.setAttribute('aria-expanded', 'true'); search.value = ''; active = -1; renderList();
    var sel = ul.querySelector('[aria-selected=true]'); if (sel) sel.scrollIntoView({ block: 'center' });
    search.focus();
  }
  function closePop() { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
  btn.addEventListener('click', function () { pop.hidden ? openPop() : closePop(); });
  search.addEventListener('input', function () { active = 0; renderList(); });
  search.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); if (!shown.length) return;
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length; renderList();
      var on = ul.querySelector('.on'); if (on) on.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') { e.preventDefault(); if (shown[active]) pickCountry(shown[active].cc); }
    else if (e.key === 'Escape') { closePop(); btn.focus(); }
  });
  ul.addEventListener('click', function (e) { var li = e.target.closest('li[data-cc]'); if (li) pickCountry(li.getAttribute('data-cc')); });
  document.addEventListener('click', function (e) { if (!pop.hidden && !e.target.closest('#tz-combo')) closePop(); });

  /** Al elegir país: si tiene una sola zona se toma sola; si tiene varias se propone la del dispositivo (si es de ese país) o la principal. */
  function pickCountry(cc, keepTz) {
    S.cc = cc; closePop();
    var zones = countries[cc] || [];
    if (!keepTz || zones.indexOf(S.tz) === -1) S.tz = zones.indexOf(T.browserTz) !== -1 ? T.browserTz : (zones[0] || '');
    render(); btn.focus();
  }

  // ── Zona, vista previa y aviso del dispositivo ──
  function render() {
    var zones = countries[S.cc] || [];
    $('tz-country-label').innerHTML = S.cc ? T.flag(S.cc) + '<span>' + esc(T.countryName(S.cc)) + '</span>' : 'Elige tu país';
    var row = $('tz-zone-row'), wrap = $('tz-zone-wrap');
    row.hidden = !S.cc;
    if (zones.length > 1) {
      $('tz-zone-desc').textContent = 'Ese país tiene ' + zones.length + ' zonas: elige la tuya';
      var opts = zones.slice().sort(function (a, b) { return (a === zones[0] ? -1 : b === zones[0] ? 1 : 0) || T.offsetMin(b) - T.offsetMin(a) || (names[a] || a).localeCompare(names[b] || b, 'es'); });
      wrap.innerHTML = '<select class="cfg-select" id="tz-zone" aria-label="Zona horaria">' + opts.map(function (z) { return '<option value="' + esc(z) + '"' + (z === S.tz ? ' selected' : '') + '>' + esc(zoneLabel(z)) + '</option>'; }).join('') + '</select>';
      $('tz-zone').addEventListener('change', function () { S.tz = this.value; render(); });
    } else if (zones.length === 1) {
      $('tz-zone-desc').textContent = 'Detectada automáticamente';
      wrap.innerHTML = '<span class="tz-auto"><i class="fa-solid fa-circle-check"></i>' + esc(zoneLabel(zones[0])) + '</span>';
    } else wrap.innerHTML = '';
    preview(); device();
  }
  function preview() {
    var tz = S.tz || D.site;
    $('tz-prev-flag').innerHTML = S.cc ? T.flag(S.cc) : '<i class="fa-solid fa-globe" style="font-size:20px;color:var(--g7-text-4)"></i>';
    $('tz-prev-time').textContent = T.clock(tz);
    var day = T.fmt(new Date(), { weekday: 'long', day: 'numeric', month: 'long' }, tz);
    $('tz-prev-sub').textContent = S.tz
      ? day + ' · ' + (names[tz] || tz) + ' (' + T.offsetLabel(tz) + ')'
      : 'Sin zona propia: ves las horas en la zona del sitio, ' + (names[tz] || tz) + ' (' + T.offsetLabel(tz) + ')';
  }
  /** Si el dispositivo marca otra zona, ofrecer usarla (no se cambia sola: el dispositivo puede estar mal configurado). */
  function device() {
    var b = T.browserTz, box = $('tz-device'), ccB = '';
    Object.keys(countries).some(function (cc) { if (countries[cc].indexOf(b) !== -1) { ccB = cc; return true; } return false; });
    if (!b || !ccB || b === S.tz) { box.hidden = true; return; }
    box.hidden = false;
    box.innerHTML = '<i class="fa-solid fa-laptop"></i><span>Este dispositivo marca ' + T.flag(ccB) + ' ' + esc(names[b] || b) + ' (' + T.clock(b) + ').</span><button type="button" class="btn btn-ghost" id="tz-use-device">Usar esta zona</button>';
    $('tz-use-device').addEventListener('click', function () { S.tz = b; pickCountry(ccB, true); });
  }
  setInterval(preview, 15000);

  // ── Guardar ──
  async function save(clear) {
    var body = clear ? { action: 'save_location', country: '', timezone: '', city: '', city_public: false }
      : { action: 'save_location', country: S.cc, timezone: S.tz, city: $('tz-city').value.trim(), city_public: $('tz-city-public').checked };
    if (!clear && !S.cc) { cfgToast('Elige tu país', true); return; }
    try {
      var r = await fetch('/toolz/user_config.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      var d = await r.json();
      if (!d.ok) { cfgToast(d.error || 'No se pudo guardar', true); return; }
      if (clear) { S.cc = ''; S.tz = ''; $('tz-city').value = ''; $('tz-city-public').checked = false; }
      T.setViewerTz(d.tz); render();
      cfgToast(clear ? 'Listo: usas la zona del sitio' : 'Zona horaria guardada ✓');
    } catch (e) { cfgToast('Error de conexión', true); }
  }
  $('tz-save').addEventListener('click', function () { save(false); });
  $('tz-clear').addEventListener('click', function () { save(true); });

  render();
})();
