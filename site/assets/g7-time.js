/* assets/g7-time.js — Fechas y horas en la zona de quien mira (par de tz_lib.php). Doc: toolz/ZONAS_HORARIAS.md
 *
 * El servidor manda las fechas como 'AAAA-MM-DD HH:MM:SS' en la zona de almacenamiento (fija por instalación).
 * Este archivo las convierte al instante real sin depender del reloj ni de la zona del navegador, y las muestra
 * en la zona elegida en Mi perfil (o la del sitio). Lo carga topbar.php con data-cfg='{"store","tz","site"}'.
 *
 * Uso: G7Time.time(s) "14:05" · G7Time.date(s) "12/11/2026" · G7Time.dateTime(s) · G7Time.dayLabel(s) "Hoy"/"Ayer"/…
 *      G7Time.short(s) (hora si es hoy, día de la semana o fecha) · G7Time.ago(s) "hace 5 min" · G7Time.sameDay(a, b)
 *      G7Time.clock(tz) hora actual en otra zona · G7Time.diffLabel(otra) "2 horas menos" · G7Time.flag(cc) bandera
 *      <span data-g7-clock="Europe/Madrid"></span> se actualiza solo cada 15 s; <time data-g7-ago="…"> también.
 */
(function () {
  'use strict';
  var cur = document.currentScript, cfg = {};
  try { cfg = JSON.parse((cur && cur.getAttribute('data-cfg')) || '{}') || {}; } catch (e) { cfg = {}; }
  var browserTz = '';
  try { browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
  var C = { store: cfg.store || browserTz || 'UTC', tz: cfg.tz || cfg.site || browserTz || 'UTC', site: cfg.site || cfg.tz || 'UTC' };
  var LOCALE = 'es';

  // ── Desfase de una zona en un instante (minutos respecto de UTC) ──
  var fmtCache = {};
  function partsFmt(tz) {
    if (!fmtCache[tz]) fmtCache[tz] = new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', weekday: 'short' });
    return fmtCache[tz];
  }
  function validTz(tz) { if (!tz) return false; try { partsFmt(tz); return true; } catch (e) { return false; } }
  var WD = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  /** Partes de reloj de un instante en una zona: {y, m, d, h, i, s, wd}. */
  function parts(ms, tz) {
    var o = {};
    partsFmt(tz).formatToParts(new Date(ms)).forEach(function (p) { o[p.type] = p.value; });
    return { y: +o.year, m: +o.month, d: +o.day, h: +o.hour % 24, i: +o.minute, s: +o.second, wd: WD[o.weekday] };
  }
  function offsetMin(tz, ms) {
    if (ms == null) ms = Date.now();
    if (!validTz(tz)) return 0;
    var p = parts(ms, tz);
    return Math.round((Date.UTC(p.y, p.m - 1, p.d, p.h, p.i, p.s) - Math.floor(ms / 1000) * 1000) / 60000);
  }
  /** Reloj de pared en una zona → instante (ms). En la hora repetida del cambio de horario toma la segunda, como PHP. */
  function wallToMs(y, mo, d, h, i, s, tz) {
    var guess = Date.UTC(y, mo - 1, d, h, i, s || 0);
    // Desfase de un día antes y de un día después: alguno de los dos es el que rige a esa hora de pared
    var o1 = offsetMin(tz, guess - 864e5), o2 = offsetMin(tz, guess + 864e5);
    var c1 = guess - o1 * 60000, c2 = guess - o2 * 60000;
    var ok1 = offsetMin(tz, c1) === o1, ok2 = offsetMin(tz, c2) === o2;
    if (ok1 && ok2) return Math.max(c1, c2);   // hora repetida al atrasar el reloj: la segunda, como PHP (servidor y navegador dan lo mismo)
    if (ok1) return c1;
    if (ok2) return c2;
    return c1;                                  // hora que no existe al adelantar el reloj: se corre hacia adelante (igual que PHP)
  }

  // ── Leer lo que manda el servidor ──
  var RX = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/;
  /** Cualquier fecha → Date (instante). 'AAAA-MM-DD HH:MM:SS' sin zona = zona de almacenamiento. Inválida → null. */
  function parse(v) {
    if (v == null || v === '') return null;
    if (v instanceof Date) return isNaN(v) ? null : v;
    if (typeof v === 'number') return new Date(v < 1e12 ? v * 1000 : v);
    var s = String(v).trim(), m = RX.exec(s);
    if (!m) { var d = new Date(s); return isNaN(d) ? null : d; }
    if (m[7]) { var d2 = new Date(s.replace(' ', 'T')); return isNaN(d2) ? null : d2; }
    if (m[4] == null) return new Date(wallToMs(+m[1], +m[2], +m[3], 12, 0, 0, C.tz));   // solo fecha: mediodía en la zona de quien mira (no se corre de día)
    return new Date(wallToMs(+m[1], +m[2], +m[3], +m[4], +m[5], +(m[6] || 0), C.store));
  }

  // ── Mostrar ──
  function fmt(v, opts, tz) {
    var d = parse(v); if (!d) return '';
    var o = {}; for (var k in (opts || {})) o[k] = opts[k];
    o.timeZone = tz && validTz(tz) ? tz : C.tz;
    try { return d.toLocaleString(LOCALE, o); } catch (e) { return d.toLocaleString(); }
  }
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  function time(v, tz) { var d = parse(v); if (!d) return ''; var p = parts(+d, tz || C.tz); return pad(p.h) + ':' + pad(p.i); }
  function date(v, tz) { var d = parse(v); if (!d) return ''; var p = parts(+d, tz || C.tz); return pad(p.d) + '/' + pad(p.m) + '/' + p.y; }
  function dateTime(v, tz) { var d = parse(v); return d ? date(d, tz) + ' ' + time(d, tz) : ''; }
  /** Día en la zona de quien mira, 'AAAA-MM-DD' (para agrupar por día). */
  function dayKey(v, tz) { var d = parse(v); if (!d) return ''; var p = parts(+d, tz || C.tz); return p.y + '-' + pad(p.m) + '-' + pad(p.d); }
  function sameDay(a, b) { return dayKey(a) !== '' && dayKey(a) === dayKey(b); }
  function daysAgo(v) {
    var k = dayKey(v); if (!k) return null;
    var a = k.split('-'), b = dayKey(new Date()).split('-');
    return Math.round((Date.UTC(+b[0], b[1] - 1, +b[2]) - Date.UTC(+a[0], a[1] - 1, +a[2])) / 864e5);
  }
  function dayLabel(v) {
    var n = daysAgo(v); if (n === null) return '';
    if (n === 0) return 'Hoy';
    if (n === 1) return 'Ayer';
    var d = parse(v), sameYear = parts(+d, C.tz).y === parts(Date.now(), C.tz).y;
    return fmt(d, { weekday: 'long', day: 'numeric', month: 'long', year: sameYear ? undefined : 'numeric' });
  }
  /** Para listas: hora si es de hoy, día de la semana si es de esta semana, si no "12 nov". */
  function short(v) {
    var n = daysAgo(v); if (n === null) return '';
    if (n === 0) return time(v);
    if (n > 0 && n < 6) return fmt(v, { weekday: 'short' });
    return fmt(v, { day: 'numeric', month: 'short' });
  }
  /** "recién", "hace 5 min", "hace 3 h", "ayer", "hace 4 días", y después la fecha. Usa instantes: no depende de zonas. */
  function ago(v) {
    var d = parse(v); if (!d) return '';
    var s = (Date.now() - d) / 1000;
    if (s < 45) return 'recién';
    if (s < 3600) return 'hace ' + Math.max(1, Math.round(s / 60)) + ' min';
    if (s < 86400) return 'hace ' + Math.round(s / 3600) + ' h';
    var n = daysAgo(d);
    if (n === 1) return 'ayer';
    if (n < 7) return 'hace ' + n + ' días';
    return fmt(d, { day: 'numeric', month: 'short', year: parts(+d, C.tz).y === parts(Date.now(), C.tz).y ? undefined : 'numeric' });
  }

  // ── Campos <input type="datetime-local"> y lo que se manda al servidor ──
  /** Instante → 'AAAA-MM-DDTHH:MM' en la zona de quien mira (valor para un datetime-local). */
  function inputValue(v, tz) { var d = parse(v); if (!d) return ''; var p = parts(+d, tz || C.tz); return p.y + '-' + pad(p.m) + '-' + pad(p.d) + 'T' + pad(p.h) + ':' + pad(p.i); }
  /** Valor de un datetime-local (hora de pared de quien mira) → Date. */
  function fromInput(s, tz) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(s || '').trim()); if (!m) return null;
    return new Date(wallToMs(+m[1], +m[2], +m[3], +m[4], +m[5], +(m[6] || 0), tz || C.tz));
  }
  /** Instante → 'AAAA-MM-DD HH:MM:SS' en la zona de almacenamiento (lo que espera el servidor). */
  function toStore(v) { var d = parse(v); if (!d) return ''; var p = parts(+d, C.store); return p.y + '-' + pad(p.m) + '-' + pad(p.d) + ' ' + pad(p.h) + ':' + pad(p.i) + ':' + pad(p.s); }
  /** Hora de pared "a tal día y hora" en la zona de quien mira, contando días desde hoy (para atajos como "mañana a las 9"). */
  function wallFromToday(addDays, h, i, tz) { var p = parts(Date.now(), tz || C.tz); return new Date(wallToMs(p.y, p.m, p.d + (addDays || 0), h || 0, i || 0, 0, tz || C.tz)); }
  /** Día de la semana de hoy (0 = domingo) en la zona de quien mira. */
  function weekday(tz) { return parts(Date.now(), tz || C.tz).wd; }
  /** Minutos desde la medianoche, ahora, en la zona de quien mira (horarios de silencio, etc.). */
  function minutesNow(tz) { var p = parts(Date.now(), tz || C.tz); return p.h * 60 + p.i; }

  // ── Otras zonas ──
  function clock(tz) { return validTz(tz) ? time(new Date(), tz) : ''; }
  function offsetLabel(tz, ms) {
    var m = offsetMin(tz, ms); if (!m) return 'UTC';
    var a = Math.abs(m);
    return 'UTC' + (m < 0 ? '−' : '+') + Math.floor(a / 60) + (a % 60 ? ':' + pad(a % 60) : '');
  }
  /** Diferencia de la zona de otra persona con la mía: "Tu misma zona horaria", "2 horas menos", "3 h 30 min más". */
  function diffLabel(other, mine, ms) {
    var d = offsetMin(other, ms) - offsetMin(mine || C.tz, ms);
    if (!d) return 'Tu misma zona horaria';
    var a = Math.abs(d), h = Math.floor(a / 60), mi = a % 60;
    var t = mi ? (h ? h + ' h ' + mi + ' min' : mi + ' min') : (h === 1 ? '1 hora' : h + ' horas');
    return t + (d < 0 ? ' menos' : ' más');
  }
  /** ¿Es de noche allá? (entre 22 y 7): para un ícono discreto de luna. */
  function isNight(tz) { if (!validTz(tz)) return false; var h = parts(Date.now(), tz).h; return h >= 22 || h < 7; }

  var regionNames = null;
  function countryName(cc) {
    cc = String(cc || '').toUpperCase(); if (!/^[A-Z]{2}$/.test(cc)) return '';
    try { regionNames = regionNames || new Intl.DisplayNames([LOCALE], { type: 'region' }); return regionNames.of(cc) || cc; } catch (e) { return cc; }
  }

  // Banderas: librería flag-icons (SVG chicos) desde jsdelivr, que la CSP permite. Se carga la primera vez que se pide una.
  var FLAG_CSS = 'https://cdn.jsdelivr.net/npm/flag-icons@7.2.3/css/flag-icons.min.css';
  var FLAG_SRI = 'sha384-aQuvIWWIbpu/mSqULLDiUveyYiPoJzPKAWjUmGJ+Elm+N/LJhzfZqsutsfw870JS';
  function ensureFlags() {
    if (document.getElementById('g7-flag-css')) return;
    var l = document.createElement('link');
    l.id = 'g7-flag-css'; l.rel = 'stylesheet'; l.href = FLAG_CSS; l.integrity = FLAG_SRI; l.crossOrigin = 'anonymous';
    (document.head || document.documentElement).appendChild(l);
    // Estilos mínimos de la bandera y del bloque de ubicación (cada página puede ajustarlos)
    var st = document.createElement('style'); st.id = 'g7-time-css';
    st.textContent = '.g7-flag{border-radius:2px;box-shadow:0 0 0 1px rgba(127,127,127,.25);flex-shrink:0}'
      + '.g7-loc{display:inline-flex;align-items:flex-start;gap:9px;line-height:1.35}.g7-loc .fi{font-size:15px;margin-top:2px}'
      + '.g7-loc-t{display:flex;flex-direction:column;min-width:0}.g7-loc-p{font-weight:600;color:var(--g7-text,inherit)}'
      + '.g7-loc-s{font-size:.9em;color:var(--g7-text-4,#888)}.g7-loc-s b{font-variant-numeric:tabular-nums;color:var(--g7-text-2,inherit)}';
    (document.head || document.documentElement).appendChild(st);
  }
  /** HTML de una bandera chica (o '' si el código no es válido). */
  function flag(cc, extraClass) {
    cc = String(cc || '').toLowerCase(); if (!/^[a-z]{2}$/.test(cc)) return '';
    ensureFlags();
    return '<span class="fi fi-' + cc + ' g7-flag' + (extraClass ? ' ' + extraClass : '') + '" role="img" aria-label="' + countryName(cc).replace(/"/g, '&quot;') + '"></span>';
  }

  /**
   * Ubicación de una persona para mostrar (perfil, fichas): {cc, tz, zone, city} como lo arma g7_tz_public() en el servidor.
   * Bandera, país (y ciudad si la compartió), hora local en vivo, zona y, si no es uno mismo, la diferencia. Sin zona: ''.
   */
  function locHtml(loc, self) {
    if (!loc || !loc.tz || !validTz(loc.tz)) return '';
    var e = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var place = countryName(loc.cc) + (loc.city ? ', ' + loc.city : '');
    return '<span class="g7-loc">' + flag(loc.cc) + '<span class="g7-loc-t"><span class="g7-loc-p">' + e(place) + '</span>'
      + '<span class="g7-loc-s"><b data-g7-clock="' + e(loc.tz) + '">' + clock(loc.tz) + '</b> hora local · ' + e(loc.zone || loc.tz) + ' (' + offsetLabel(loc.tz) + ')'
      + (self ? '' : ' · ' + e(diffLabel(loc.tz))) + '</span></span></span>';
  }
  /** Rellena los elementos con data-g7-loc='{"cc":…,"tz":…}' (y data-g7-self si es el propio perfil). */
  function fillLoc(root) {
    (root || document).querySelectorAll('[data-g7-loc]:not([data-g7-done])').forEach(function (el) {
      el.setAttribute('data-g7-done', '1');
      try { var h = locHtml(JSON.parse(el.getAttribute('data-g7-loc')), el.hasAttribute('data-g7-self')); if (h) { el.innerHTML = h; el.hidden = false; } } catch (e) {}
    });
  }

  // ── Relojes y "hace X" que se actualizan solos ──
  function tick() {
    document.querySelectorAll('[data-g7-clock]').forEach(function (el) {
      var t = clock(el.getAttribute('data-g7-clock')); if (t && el.textContent !== t) el.textContent = t;
    });
    document.querySelectorAll('[data-g7-ago]').forEach(function (el) {
      var t = ago(el.getAttribute('data-g7-ago')); if (t && el.textContent !== t) el.textContent = t;
    });
  }
  var timer = null;
  function startTicker() {
    if (timer) return;
    // Alineado al cambio de minuto para que el reloj no se quede atrasado
    setTimeout(function () { tick(); timer = setInterval(tick, 15000); }, 60000 - (Date.now() % 60000) + 50);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { fillLoc(); tick(); startTicker(); });
  else { fillLoc(); tick(); startTicker(); }

  window.G7Time = {
    cfg: C, browserTz: browserTz, parse: parse, fmt: fmt, time: time, date: date, dateTime: dateTime, dayKey: dayKey, sameDay: sameDay,
    daysAgo: daysAgo, dayLabel: dayLabel, short: short, ago: ago, clock: clock, offsetMin: offsetMin, offsetLabel: offsetLabel,
    diffLabel: diffLabel, isNight: isNight, inputValue: inputValue, fromInput: fromInput, toStore: toStore, wallFromToday: wallFromToday,
    weekday: weekday, minutesNow: minutesNow, locHtml: locHtml, fillLoc: fillLoc, countryName: countryName, flag: flag, ensureFlags: ensureFlags, tick: tick, wallToMs: wallToMs, parts: parts,
    /** Cambia la zona de quien mira sin recargar (Mi perfil, al guardar). */
    setViewerTz: function (tz) { if (validTz(tz)) { C.tz = tz; tick(); } }
  };
})();
