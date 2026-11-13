/* assets/stats-jira.js — Estadísticas › Jira (stats_jira.php): pestañas, gráficos, lista filtrable y la ventana de exportar.
   Los datos vienen ya calculados por el servidor (g7_jira_stats en jira_stats_lib.php) en #js-data. Sin código en línea (CSP). */
(function () {
  'use strict';
  var box = document.getElementById('js-data'); if (!box) return;
  var R; try { R = JSON.parse(box.dataset.report || '{}'); } catch (e) { return; }
  var V = document.getElementById('js-view'), hasChart = typeof Chart !== 'undefined';
  var css = getComputedStyle(document.documentElement), txt = (css.getPropertyValue('--g7-text-4') || '#888').trim(), grid = 'rgba(128,128,128,.12)';
  var SIT = R.sit || {}, SIT_OPEN = ['overdue', 'in_progress', 'not_started'], SIT_MAIN = ['on_time', 'late_done', 'overdue', 'in_progress', 'not_started'];
  var PAL = ['#4c9aff', '#a78bfa', '#22c55e', '#f59e0b', '#ef4444', '#14b8a6', '#ec4899', '#94a3b8', '#f97316', '#84cc16', '#06b6d4', '#eab308'];
  var MON = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  var DIMS = { person: 'Persona', reporter: 'Informante (quién lo pidió)', component: 'Componente', label: 'Etiqueta', type: 'Tipo', priority: 'Prioridad', project: 'Proyecto', status: 'Estado' };
  var METRICS = { total: 'Issues en el informe', created: 'Creados', resolved: 'Entregados', open: 'Abiertos', overdue: 'Atrasados', on_time: 'Entregados a tiempo',
    late_done: 'Entregados tarde', in_progress: 'En curso', not_started: 'Sin empezar', awaiting: 'Esperando aprobación', due_weekend: 'Entrega en fin de semana',
    on_time_pct: '% a tiempo', avg_days: 'Días promedio hasta entregar', avg_lead: 'Plazo promedio que se da (días)' };
  var S = { tab: 'resumen', sit: '', flag: '', who: '', q: '', sort: '', dir: 1, shown: 100, dim: 'person', metric: 'total', chart: 'bar', top: '15',
    prio: '', range: null, dayf: null, dimf: null, mx: 'lead', calMode: 'created' };
  var OPEN = ['overdue', 'in_progress', 'not_started'];
  var isOpen = function (i) { return OPEN.indexOf(i.situation) >= 0; };
  var DOW = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
  var MONL = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  // Orden de prioridades de Jira (las que no conoce van al final, por nombre)
  var PRIO_ORD = ['highest', 'blocker', 'critical', 'crítica', 'urgente', 'high', 'alta', 'major', 'medium', 'media', 'normal', 'low', 'baja', 'minor', 'lowest', 'trivial', 'sin prioridad'];
  var prioSort = function (a, b) { var x = PRIO_ORD.indexOf(a.toLowerCase()), y = PRIO_ORD.indexOf(b.toLowerCase()); x = x < 0 ? 99 : x; y = y < 0 ? 99 : y; return x - y || a.localeCompare(b); };
  // Tramos de días para la matriz Días vs prioridad
  var MX = {
    lead: { label: 'Plazo que se dio (de creado a la fecha de entrega)', get: function (i) { return i.lead; }, b: [[null, -1, 'Vencido al crearse'], [0, 0, 'Mismo día'], [1, 1, '1 día'], [2, 3, '2–3 d'], [4, 7, '4–7 d'], [8, 14, '8–14 d'], [15, 30, '15–30 d'], [31, null, '+30 d']] },
    res: { label: 'Días que tardó en entregarse', get: function (i) { return i.res_days; }, b: [[null, 0.999, '< 1 día'], [1, 2.999, '1–3 d'], [3, 6.999, '3–7 d'], [7, 13.999, '1–2 sem'], [14, 29.999, '2–4 sem'], [30, null, '+1 mes']] },
    age: { label: 'Antigüedad de lo que sigue sin entregar', get: function (i) { return isOpen(i) ? i.age : null; }, b: [[null, 2, '0–2 d'], [3, 7, '3–7 d'], [8, 14, '8–14 d'], [15, 30, '15–30 d'], [31, 60, '1–2 meses'], [61, null, '+2 meses']] },
    late: { label: 'Días de atraso (atrasados y entregados tarde)', get: function (i) { return i.late > 0 ? i.late : null; }, b: [[1, 1, '1 día'], [2, 3, '2–3 d'], [4, 7, '4–7 d'], [8, 14, '8–14 d'], [15, 30, '15–30 d'], [31, null, '+30 d']] }
  };
  var inB = function (v, b) { return v != null && (b[0] == null || v >= b[0]) && (b[1] == null || v <= b[1]); };
  var dimMatch = function (i, f) {
    if (f.dim === 'reporter') return (i.reporter || 'Sin informante') === f.name;
    if (f.dim === 'component') return (i.components && i.components.length ? i.components : ['Sin componente']).indexOf(f.name) >= 0;
    if (f.dim === 'label') return (i.labels && i.labels.length ? i.labels : ['Sin etiqueta']).indexOf(f.name) >= 0;
    if (f.dim === 'person') return (i.assignee || 'Sin asignar') === f.name;
    return String(i[f.dim]) === f.name;
  };
  var charts = [];

  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var num = function (v) { return v == null ? '—' : String(v).replace('.', ','); };
  var day = function (d) { return d ? +d.slice(8, 10) + ' ' + MON[+d.slice(5, 7) - 1] + (d.slice(0, 4) !== R.today.slice(0, 4) ? ' ' + d.slice(2, 4) : '') : ''; };
  var sitTag = function (s) { var x = SIT[s] || [s, '#888', 'fa-circle']; return '<span class="js-tag" style="--c:' + x[1] + '"><i class="fa-solid ' + x[2] + '"></i>' + esc(x[0]) + '</span>'; };
  var jiraUrl = function (k) { return esc(R.site + '/browse/' + encodeURIComponent(k)); };
  var keyLink = function (k) { return '<a class="js-key" href="' + jiraUrl(k) + '" target="_blank" rel="noopener noreferrer">' + esc(k) + '</a>'; };
  var empty = function (t) { return '<div class="js-empty">' + esc(t) + '</div>'; };
  var card = function (icon, title, body, extra) { return '<div class="card"' + (extra || '') + '><div class="card-title"><i class="fa-solid ' + icon + '"></i> ' + title + '</div>' + body + '</div>'; };
  var canvas = function (id, cls) { return hasChart ? '<div class="js-chart ' + (cls || '') + '"><canvas id="' + id + '"></canvas></div>' : empty('No se pudieron cargar los gráficos.'); };
  var label = function (s) {
    if (R.gran === 'month') return MON[+s.slice(5, 7) - 1] + ' ' + s.slice(2, 4);
    if (R.gran === 'week') return 'Sem ' + s.slice(-2);
    return s.slice(8, 10) + '/' + s.slice(5, 7);
  };
  var axes = function (stacked, horizontal) {
    var a = { ticks: { color: txt, maxRotation: 0, autoSkip: true, maxTicksLimit: 14 }, grid: { display: false }, stacked: !!stacked };
    var b = { beginAtZero: true, ticks: { color: txt, precision: 0 }, grid: { color: grid }, stacked: !!stacked };
    return horizontal ? { x: b, y: a } : { x: a, y: b };
  };
  var legend = { labels: { color: txt, boxWidth: 12, font: { size: 11 } } };
  var make = function (id, cfg) { var el = document.getElementById(id); if (!el || !hasChart) return; cfg.options = Object.assign({ responsive: true, maintainAspectRatio: false }, cfg.options || {}); charts.push(new Chart(el, cfg)); };
  var donut = function (id, labels, data, colors) {
    if (!data.some(function (x) { return x > 0; })) { var el = document.getElementById(id); if (el) el.parentNode.innerHTML = empty('Sin datos en el rango.'); return; }
    make(id, { type: 'doughnut', data: { labels: labels, datasets: [{ data: data, backgroundColor: colors || labels.map(function (l, i) { return PAL[i % PAL.length]; }), borderWidth: 0 }] },
      options: { cutout: '62%', plugins: { legend: { position: 'right', labels: legend.labels } } } });
  };
  var hbars = function (rows, val, color, fmt) {
    var mx = Math.max.apply(null, [1].concat(rows.map(val)));
    return rows.length ? '<div class="js-bars">' + rows.map(function (r) { var v = val(r); return '<div class="js-bar"><span>' + esc(r.label || r.name) + '</span><i style="--w:' + Math.round((v || 0) / mx * 100) + '%;--c:' + (typeof color === 'function' ? color(r) : color) + '"></i><b>' + (fmt ? fmt(v) : v) + '</b></div>'; }).join('') + '</div>' : empty('Sin datos.');
  };
  var meter = function (g) {
    var t = SIT_MAIN.reduce(function (s, k) { return s + (g[k] || 0); }, 0) || 1;
    return '<div class="js-meter" title="' + SIT_MAIN.map(function (k) { return SIT[k][0] + ': ' + (g[k] || 0); }).join(' · ') + '">' + SIT_MAIN.map(function (k) { return g[k] ? '<span style="--c:' + SIT[k][1] + ';width:' + (g[k] / t * 100) + '%"></span>' : ''; }).join('') + '</div>';
  };

  // ── Pestañas ───────────────────────────────────────────────
  var VIEWS = {
    resumen: function () {
      var st = (R.groups.status || []).filter(function (s) { return s.open > 0; }), cc = { 'new': '#94a3b8', indeterminate: '#4c9aff', done: '#22c55e' };
      V.innerHTML = card('fa-chart-column', 'Creados, resueltos y pendientes ' + ({ day: 'por día', week: 'por semana', month: 'por mes' })[R.gran], canvas('jsFlow', 'tall'))
        + '<div class="js-g2">' + card('fa-clipboard-list', 'Situación de los pedidos', canvas('jsSit'))
        + card('fa-list-check', 'Estados de lo abierto', hbars(st, function (s) { return s.open; }, function (s) { return cc[s.cat] || '#4c9aff'; })) + '</div>'
        + '<div class="js-g2">' + card('fa-shapes', 'Tipos (creados en el rango)', canvas('jsTypes')) + card('fa-signal', 'Prioridad de lo abierto', canvas('jsPrio')) + '</div>';
      var s = R.series || [];
      make('jsFlow', { data: { labels: s.map(function (x) { return label(x.label); }), datasets: [
        { type: 'bar', label: 'Creados', data: s.map(function (x) { return x.created; }), backgroundColor: '#4c9affcc', borderRadius: 4, order: 2 },
        { type: 'bar', label: 'Resueltos', data: s.map(function (x) { return x.resolved; }), backgroundColor: '#22c55ecc', borderRadius: 4, order: 2 },
        { type: 'line', label: 'Pendientes al cierre', data: s.map(function (x) { return x.backlog; }), borderColor: '#f59e0b', backgroundColor: '#f59e0b', pointRadius: s.length > 40 ? 0 : 2, tension: .3, yAxisID: 'y2', order: 1 }] },
        options: { interaction: { mode: 'index', intersect: false }, plugins: { legend: legend },
          scales: Object.assign(axes(), { y2: { position: 'right', beginAtZero: true, ticks: { color: '#f59e0b', precision: 0 }, grid: { display: false } } }) } });
      donut('jsSit', SIT_MAIN.map(function (k) { return SIT[k][0]; }), SIT_MAIN.map(function (k) { return R.kpi[k] || 0; }), SIT_MAIN.map(function (k) { return SIT[k][1]; }));
      var ty = (R.groups.type || []).filter(function (x) { return x.created > 0; }), pr = (R.groups.priority || []).filter(function (x) { return x.open > 0; });
      donut('jsTypes', ty.map(function (x) { return x.name; }), ty.map(function (x) { return x.created; }));
      donut('jsPrio', pr.map(function (x) { return x.name; }), pr.map(function (x) { return x.open; }));
    },
    entregas: function () {
      var ppl = (R.groups.person || []).filter(function (p) { return p.resolved > 0; }).sort(function (a, b) { return b.resolved - a.resolved; });
      var late = R.issues.filter(function (i) { return i.situation === 'late_done'; }).sort(function (a, b) { return b.late - a.late; }).slice(0, 12);
      var over = R.issues.filter(function (i) { return i.situation === 'overdue'; }).slice(0, 12);
      var row = function (i, t) { return '<tr><td>' + keyLink(i.key) + '</td><td>' + esc(i.summary) + '<br><span class="js-mut">' + esc(i.assignee || 'sin asignar') + ' · entrega ' + day(i.due) + '</span></td><td class="r js-late">' + i.late + ' d ' + t + '</td></tr>'; };
      V.innerHTML = '<div class="js-g3">' + card('fa-truck-fast', 'Entregas a tiempo y tarde ' + ({ day: 'por día', week: 'por semana', month: 'por mes' })[R.gran], canvas('jsDeliv', 'tall'))
        + card('fa-bullseye', 'Cumplimiento', '<div class="js-chart tall"><canvas id="jsOnTime"></canvas></div>') + '</div>'
        + card('fa-user-check', 'Cumplimiento por persona', ppl.length ? '<div class="js-tblwrap"><table class="js-tbl"><tr><th>Persona</th><th class="r">Entregados</th><th class="r">A tiempo</th><th class="r">Tarde</th><th class="r">% a tiempo</th><th class="r">Días prom.</th><th>Situación</th></tr>'
          + ppl.map(function (p) { return '<tr><td>' + esc(p.name) + '</td><td class="r">' + p.resolved + '</td><td class="r">' + p.on_time + '</td><td class="r' + (p.late_done ? ' js-late' : '') + '">' + p.late_done + '</td><td class="r"><b>' + num(p.on_time_pct) + (p.on_time_pct == null ? '' : '%') + '</b></td><td class="r">' + num(p.avg_days) + '</td><td>' + meter(p) + '</td></tr>'; }).join('') + '</table></div>' : empty('Nadie entregó nada en el rango.'))
        + '<div class="js-g2">' + card('fa-clock-rotate-left', 'Entregados tarde', late.length ? '<table class="js-tbl">' + late.map(function (i) { return row(i, 'tarde'); }).join('') + '</table>' : empty('Nada se entregó tarde.'))
        + card('fa-triangle-exclamation', 'Atrasados (abiertos y vencidos)', over.length ? '<table class="js-tbl">' + over.map(function (i) { return row(i, 'de atraso'); }).join('') + '</table>' : empty('Nada atrasado.')) + '</div>';
      var s = R.series || [];
      make('jsDeliv', { type: 'bar', data: { labels: s.map(function (x) { return label(x.label); }), datasets: [
        { label: 'A tiempo', data: s.map(function (x) { return x.on_time; }), backgroundColor: SIT.on_time[1] + 'cc', borderRadius: 3 },
        { label: 'Tarde', data: s.map(function (x) { return x.late_done; }), backgroundColor: SIT.late_done[1] + 'cc', borderRadius: 3 }] },
        options: { interaction: { mode: 'index', intersect: false }, plugins: { legend: legend }, scales: axes(true) } });
      donut('jsOnTime', ['A tiempo', 'Tarde'], [R.kpi.on_time, R.kpi.late_done], [SIT.on_time[1], SIT.late_done[1]]);
    },
    personas: function () {
      var ppl = (R.groups.person || []).slice(0, 25);
      V.innerHTML = card('fa-users', 'Pedidos de cada persona por situación', ppl.length ? '<div class="js-chart" style="height:' + Math.max(220, ppl.length * 30 + 60) + 'px"><canvas id="jsPeople"></canvas></div>' : empty('Sin datos.'))
        + card('fa-table', 'Detalle por persona <span class="js-mut">· tocá una fila para ver sus issues</span>', ppl.length ? '<div class="js-tblwrap"><table class="js-tbl" id="js-ptbl"><tr><th>Persona</th><th class="r">Total</th><th class="r">Abiertos</th><th class="r">Atrasados</th><th class="r">En curso</th><th class="r">Sin empezar</th><th class="r">Entregados</th><th class="r">% a tiempo</th><th class="r">Días prom.</th></tr>'
          + ppl.map(function (p) { return '<tr data-who="' + esc(p.name) + '" style="cursor:pointer"><td><b>' + esc(p.name) + '</b></td><td class="r">' + p.total + '</td><td class="r">' + p.open + '</td><td class="r' + (p.overdue ? ' js-late' : '') + '">' + p.overdue + '</td><td class="r">' + p.in_progress + '</td><td class="r">' + p.not_started + '</td><td class="r">' + p.resolved + '</td><td class="r">' + num(p.on_time_pct) + (p.on_time_pct == null ? '' : '%') + '</td><td class="r">' + num(p.avg_days) + '</td></tr>'; }).join('') + '</table></div>' : '');
      make('jsPeople', { type: 'bar', data: { labels: ppl.map(function (p) { return p.name; }), datasets: SIT_MAIN.map(function (k) { return { label: SIT[k][0], data: ppl.map(function (p) { return p[k]; }), backgroundColor: SIT[k][1] + 'dd', borderRadius: 3 }; }) },
        options: { indexAxis: 'y', interaction: { mode: 'index', intersect: false }, plugins: { legend: legend }, scales: axes(true, true) } });
      var t = document.getElementById('js-ptbl');
      if (t) t.addEventListener('click', function (e) { var tr = e.target.closest('tr[data-who]'); if (tr) goList({ who: tr.dataset.who }); });
    },
    tiempos: function () {
      var h = R.res_hist || {}, hk = Object.keys(h);
      var byType = (R.groups.type || []).filter(function (x) { return x.avg_days != null; }).sort(function (a, b) { return b.avg_days - a.avg_days; });
      var byPrio = (R.groups.priority || []).filter(function (x) { return x.avg_days != null; }).sort(function (a, b) { return b.avg_days - a.avg_days; });
      var old = R.issues.filter(function (i) { return SIT_OPEN.indexOf(i.situation) >= 0; }).sort(function (a, b) { return (b.age || 0) - (a.age || 0); }).slice(0, 12);
      V.innerHTML = '<div class="js-g3">' + card('fa-stopwatch', 'Cuánto tardó en resolverse lo entregado en el rango', canvas('jsHist'))
        + card('fa-gauge', 'Promedio y mediana', '<div class="js-bars" style="padding-top:8px"><div class="js-bar"><span>Promedio</span><i style="--w:100%;--c:#f59e0b"></i><b>' + num(R.kpi.avg_days) + ' d</b></div><div class="js-bar"><span>Mediana</span><i style="--w:' + (R.kpi.avg_days ? Math.round(R.kpi.median_days / R.kpi.avg_days * 100) : 0) + '%;--c:#14b8a6"></i><b>' + num(R.kpi.median_days) + ' d</b></div></div><p class="js-mut" style="margin-top:12px">La mediana es el valor del medio: la mitad se resolvió más rápido. Si es mucho menor que el promedio, hay pocos issues que tardaron muchísimo.</p>') + '</div>'
        + '<div class="js-g2">' + card('fa-shapes', 'Días promedio por tipo', hbars(byType, function (x) { return x.avg_days; }, '#a78bfa', function (v) { return num(v) + ' d'; }))
        + card('fa-signal', 'Días promedio por prioridad', hbars(byPrio, function (x) { return x.avg_days; }, '#f59e0b', function (v) { return num(v) + ' d'; })) + '</div>'
        + card('fa-hourglass-half', 'Abiertos hace más tiempo', old.length ? '<table class="js-tbl">' + old.map(function (i) { return '<tr><td>' + keyLink(i.key) + '</td><td>' + esc(i.summary) + '<br><span class="js-mut">' + esc(i.status) + ' · ' + esc(i.assignee || 'sin asignar') + '</span></td><td>' + sitTag(i.situation) + '</td><td class="r">' + (i.age == null ? '' : i.age + ' d') + '</td></tr>'; }).join('') + '</table>' : empty('No hay nada abierto.'));
      make('jsHist', { type: 'bar', data: { labels: hk, datasets: [{ label: 'Issues', data: hk.map(function (k) { return h[k]; }), backgroundColor: '#14b8a6cc', borderRadius: 4 }] }, options: { plugins: { legend: { display: false } }, scales: axes() } });
    },
    explorar: function () {
      var opt = function (o, cur) { return Object.keys(o).map(function (k) { return '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + esc(o[k]) + '</option>'; }).join(''); };
      var seg = [['bar', 'Barras'], ['hbar', 'Horizontales'], ['stack', 'Por situación'], ['doughnut', 'Dona'], ['pie', 'Torta'], ['table', 'Tabla']];
      V.innerHTML = '<div class="card"><div class="js-ctl">'
        + '<label class="js-lbl">Agrupar por<select id="jx-dim">' + opt(DIMS, S.dim) + '</select></label>'
        + '<label class="js-lbl">Medir<select id="jx-metric">' + opt(METRICS, S.metric) + '</select></label>'
        + '<label class="js-lbl">Mostrar<select id="jx-top">' + opt({ 5: 'Los 5 primeros', 10: 'Los 10 primeros', 15: 'Los 15 primeros', 30: 'Los 30 primeros', all: 'Todos' }, S.top) + '</select></label>'
        + '<div class="js-lbl">Gráfico<div class="js-seg" id="jx-chart">' + seg.map(function (s) { return '<button type="button" data-c="' + s[0] + '"' + (s[0] === S.chart ? ' class="on"' : '') + '>' + s[1] + '</button>'; }).join('') + '</div></div>'
        + '</div><div id="jx-out"></div></div>';
      var draw = function () {
        charts.forEach(function (c) { c.destroy(); }); charts = [];
        var g = (R.groups[S.dim] || []).slice(), m = S.metric;
        g = g.filter(function (x) { return x[m] != null && (S.chart === 'stack' || x[m] > 0); }).sort(function (a, b) { return (b[m] || 0) - (a[m] || 0); });
        if (S.top !== 'all') g = g.slice(0, +S.top);
        var out = document.getElementById('jx-out'), name = function (x) { return S.dim === 'project' && x.label && x.label !== x.name ? x.name + ' · ' + x.label : x.name; };
        var fmt = function (v) { return m === 'on_time_pct' ? num(v) + '%' : m === 'avg_days' ? num(v) + ' d' : v; };
        if (!g.length) { out.innerHTML = empty('No hay datos para esa combinación.'); return; }
        if (S.chart === 'table' || !hasChart) {
          out.innerHTML = '<div class="js-tblwrap"><table class="js-tbl"><tr><th>' + DIMS[S.dim] + '</th><th class="r">' + esc(METRICS[m]) + '</th><th class="r">Total</th><th class="r">Abiertos</th><th class="r">Atrasados</th><th class="r">Entregados</th><th class="r">% a tiempo</th><th>Situación</th></tr>'
            + g.map(function (x) { return '<tr><td><b>' + esc(name(x)) + '</b></td><td class="r"><b>' + fmt(x[m]) + '</b></td><td class="r">' + x.total + '</td><td class="r">' + x.open + '</td><td class="r">' + x.overdue + '</td><td class="r">' + x.resolved + '</td><td class="r">' + num(x.on_time_pct) + '</td><td>' + meter(x) + '</td></tr>'; }).join('') + '</table></div>';
          return;
        }
        var horiz = S.chart === 'hbar' || S.chart === 'stack';
        out.innerHTML = '<div class="js-chart" style="height:' + (horiz ? Math.max(240, g.length * 28 + 60) : 340) + 'px"><canvas id="jxC"></canvas></div>';
        if (S.chart === 'pie' || S.chart === 'doughnut') {
          make('jxC', { type: S.chart, data: { labels: g.map(name), datasets: [{ data: g.map(function (x) { return x[m]; }), backgroundColor: g.map(function (x, i) { return PAL[i % PAL.length]; }), borderWidth: 0 }] },
            options: { cutout: S.chart === 'doughnut' ? '58%' : 0, plugins: { legend: { position: 'right', labels: legend.labels } } } });
        } else if (S.chart === 'stack') {
          make('jxC', { type: 'bar', data: { labels: g.map(name), datasets: SIT_MAIN.map(function (k) { return { label: SIT[k][0], data: g.map(function (x) { return x[k]; }), backgroundColor: SIT[k][1] + 'dd', borderRadius: 3 }; }) },
            options: { indexAxis: 'y', interaction: { mode: 'index', intersect: false }, plugins: { legend: legend }, scales: axes(true, true) } });
        } else {
          make('jxC', { type: 'bar', data: { labels: g.map(name), datasets: [{ label: METRICS[m], data: g.map(function (x) { return x[m]; }), backgroundColor: g.map(function (x, i) { return PAL[i % PAL.length] + 'dd'; }), borderRadius: 4 }] },
            options: { indexAxis: horiz ? 'y' : 'x', plugins: { legend: { display: false } }, scales: axes(false, horiz) } });
        }
      };
      ['dim', 'metric', 'top'].forEach(function (k) { document.getElementById('jx-' + k).addEventListener('change', function (e) { S[k] = e.target.value; draw(); }); });
      document.getElementById('jx-chart').addEventListener('click', function (e) {
        var b = e.target.closest('[data-c]'); if (!b) return; S.chart = b.dataset.c;
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); }); draw();
      });
      draw();
    },
    plazos: function () {
      var h = R.lead_hist || {}, hk = R.lead_buckets || Object.keys(h);
      var pr = (R.groups.priority || []).slice().sort(function (a, b) { return prioSort(a.name, b.name); });
      V.innerHTML = '<div class="js-g3">' + card('fa-hourglass-half', 'Qué plazo se suele dar <span class="js-mut">· de creado a la fecha de entrega, de lo creado en el rango</span>', canvas('jsLead'))
        + card('fa-gauge', 'En números', '<div class="js-bars" style="padding-top:8px"><div class="js-bar"><span>Promedio</span><i style="--w:100%;--c:#ec4899"></i><b>' + num(R.kpi.avg_lead) + ' d</b></div><div class="js-bar"><span>Mediana</span><i style="--w:' + (R.kpi.avg_lead ? Math.min(100, Math.round(R.kpi.median_lead / R.kpi.avg_lead * 100)) : 0) + '%;--c:#14b8a6"></i><b>' + num(R.kpi.median_lead) + ' d</b></div></div>'
          + '<p class="js-mut" style="margin-top:12px">' + (R.kpi.with_due || 0) + ' de ' + (R.kpi.created || 0) + ' pedidos creados en el rango tienen fecha de entrega. ' + (R.kpi.due_weekend ? '<b>' + R.kpi.due_weekend + '</b> tienen la entrega en sábado o domingo.' : 'Ninguno tiene la entrega en fin de semana.') + '</p>') + '</div>'
        + card('fa-signal', 'Plazo y entrega por prioridad', pr.length ? '<div class="js-tblwrap"><table class="js-tbl" id="jp-t"><tr><th>Prioridad</th><th class="r">Pedidos</th><th class="r">Plazo promedio</th><th class="r">Plazo mediana</th><th class="r">Días hasta entregar</th><th class="r">% a tiempo</th><th class="r">Atrasados</th><th class="r">Entrega en fin de semana</th></tr>'
          + pr.map(function (p) { return '<tr data-prio="' + esc(p.name) + '" style="cursor:pointer"><td><b>' + esc(p.name) + '</b></td><td class="r">' + p.total + '</td><td class="r">' + num(p.avg_lead) + (p.avg_lead == null ? '' : ' d') + '</td><td class="r">' + num(p.median_lead) + (p.median_lead == null ? '' : ' d') + '</td><td class="r">' + num(p.avg_days) + (p.avg_days == null ? '' : ' d') + '</td><td class="r">' + num(p.on_time_pct) + (p.on_time_pct == null ? '' : '%') + '</td><td class="r' + (p.overdue ? ' js-late' : '') + '">' + p.overdue + '</td><td class="r">' + p.due_weekend + '</td></tr>'; }).join('') + '</table></div>' : empty('Sin datos.'))
        + '<div class="card"><div class="card-title"><i class="fa-solid fa-table-cells"></i> Días vs prioridad <span class="js-mut">· tocá una celda para ver esos pedidos</span></div><div class="js-ctl"><label class="js-lbl">Qué días<select id="jm-mx">'
          + Object.keys(MX).map(function (k) { return '<option value="' + k + '"' + (k === S.mx ? ' selected' : '') + '>' + esc(MX[k].label) + '</option>'; }).join('') + '</select></label></div><div class="js-tblwrap" id="jm-out"></div></div>';
      make('jsLead', { type: 'bar', data: { labels: hk, datasets: [{ label: 'Pedidos', data: hk.map(function (k) { return h[k] || 0; }), backgroundColor: hk.map(function (k, i) { return i === 0 ? '#ef4444cc' : '#ec4899cc'; }), borderRadius: 4 }] }, options: { plugins: { legend: { display: false } }, scales: axes() } });
      var drawM = function () {
        var m = MX[S.mx], rows = {}, tot = 0, mxv = 1;
        R.issues.forEach(function (i) { var v = m.get(i); if (v == null) return; var bi = -1; m.b.forEach(function (b, x) { if (bi < 0 && inB(v, b)) bi = x; }); if (bi < 0) return; (rows[i.priority] = rows[i.priority] || m.b.map(function () { return 0; }))[bi]++; tot++; });
        var names = Object.keys(rows).sort(prioSort);
        names.forEach(function (n) { rows[n].forEach(function (c) { mxv = Math.max(mxv, c); }); });
        document.getElementById('jm-out').innerHTML = !tot ? empty('No hay pedidos con ese dato en el rango.') : '<table class="js-tbl js-mx"><tr><th>Prioridad</th>' + m.b.map(function (b) { return '<th class="r">' + esc(b[2]) + '</th>'; }).join('') + '<th class="r">Total</th></tr>'
          + names.map(function (n) { var sum = rows[n].reduce(function (a, c) { return a + c; }, 0); return '<tr><td><b>' + esc(n) + '</b></td>' + rows[n].map(function (c, x) { return '<td class="r js-hc' + (c ? ' on' : '') + '" style="--a:' + (c ? 0.12 + c / mxv * 0.75 : 0) + '"' + (c ? ' data-mx="' + x + '" data-p="' + esc(n) + '"' : '') + '>' + (c || '') + '</td>'; }).join('') + '<td class="r"><b>' + sum + '</b></td></tr>'; }).join('') + '</table>';
      };
      document.getElementById('jm-mx').addEventListener('change', function (e) { S.mx = e.target.value; drawM(); });
      document.getElementById('jm-out').addEventListener('click', function (e) {
        var c = e.target.closest('[data-mx]'); if (c) { var b = MX[S.mx].b[+c.dataset.mx]; goList({ prio: c.dataset.p, range: { mx: S.mx, b: b, label: MX[S.mx].label.split(' (')[0] + ': ' + b[2] } }); }
      });
      var pt = document.getElementById('jp-t');
      if (pt) pt.addEventListener('click', function (e) { var r = e.target.closest('tr[data-prio]'); if (r) goList({ prio: r.dataset.prio }); });
      drawM();
    },
    calendario: function () {
      var modes = { created: ['Pedidos creados', 'created'], due: ['Fechas de entrega pedidas', 'due'], delivered: ['Entregas realizadas', 'delivered_at'] };
      var cal = R.calendar || {}, ds = Object.keys(cal).sort();
      var draw = function () {
        var key = S.calMode, mx = 1, tot = 0, wk = [0, 0, 0, 0, 0, 0, 0], months = {};
        ds.forEach(function (d) { var v = cal[d][key] || 0; mx = Math.max(mx, v); tot += v; var w = (new Date(d + 'T12:00:00').getDay() + 6) % 7; wk[w] += v; (months[d.slice(0, 7)] = months[d.slice(0, 7)] || []).push(d); });
        var html = '<div class="js-cal-wrap">' + Object.keys(months).map(function (m) {
          var first = months[m][0], lead = (new Date(m + '-01T12:00:00').getDay() + 6) % 7, days = new Date(+m.slice(0, 4), +m.slice(5, 7), 0).getDate(), cells = '';
          for (var x = 0; x < lead; x++) cells += '<span class="js-cd out"></span>';
          for (var d = 1; d <= days; d++) {
            var iso = m + '-' + (d < 10 ? '0' : '') + d, has = cal[iso], v = has ? (cal[iso][key] || 0) : 0, w = (lead + d - 1) % 7;
            cells += '<button type="button" class="js-cd' + (has ? '' : ' out') + (w >= 5 ? ' wk' : '') + (v ? ' on' : '') + (iso === R.today ? ' today' : '') + '" style="--a:' + (v ? 0.15 + v / mx * 0.8 : 0) + '"' + (has && v ? ' data-day="' + iso + '"' : '') + ' title="' + esc(day(iso)) + ': ' + v + '"><small>' + d + '</small>' + (v ? '<b>' + v + '</b>' : '') + '</button>';
          }
          return '<div class="js-cal"><div class="js-cal-h">' + MONL[+m.slice(5, 7) - 1] + ' ' + m.slice(0, 4) + '</div><div class="js-cal-g">' + DOW.map(function (n, i) { return '<span class="js-cdh' + (i >= 5 ? ' wk' : '') + '">' + n + '</span>'; }).join('') + cells + '</div></div>';
        }).join('') + '</div>';
        document.getElementById('jc-out').innerHTML = html + '<p class="js-mut" style="margin-top:10px">' + tot + ' en el rango · el más alto: ' + mx + ' en un día · sábados y domingos sombreados.</p>';
        charts.forEach(function (c) { c.destroy(); }); charts = [];
        make('jsWk', { type: 'bar', data: { labels: DOW, datasets: [{ label: modes[key][0], data: wk, backgroundColor: DOW.map(function (d, i) { return i >= 5 ? '#f59e0bcc' : '#4c9affcc'; }), borderRadius: 4 }] }, options: { plugins: { legend: { display: false } }, scales: axes() } });
      };
      V.innerHTML = '<div class="card"><div class="card-title"><i class="fa-regular fa-calendar"></i> Pedidos por día <span class="js-mut">· tocá un día para ver esos pedidos</span></div>'
        + '<div class="js-ctl"><div class="js-lbl">Qué contar<div class="js-seg" id="jc-mode">' + Object.keys(modes).map(function (k) { return '<button type="button" data-m="' + k + '"' + (k === S.calMode ? ' class="on"' : '') + '>' + modes[k][0] + '</button>'; }).join('') + '</div></div></div><div id="jc-out"></div></div>'
        + card('fa-calendar-week', 'Por día de la semana', canvas('jsWk', 'sm'));
      document.getElementById('jc-mode').addEventListener('click', function (e) { var b = e.target.closest('[data-m]'); if (!b) return; S.calMode = b.dataset.m; this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); }); draw(); });
      document.getElementById('jc-out').addEventListener('click', function (e) { var c = e.target.closest('[data-day]'); if (c) goList({ dayf: { field: modes[S.calMode][1], date: c.dataset.day, label: modes[S.calMode][0] + ' el ' + day(c.dataset.day) } }); });
      draw();
    },
    origen: function () {
      var rep = (R.groups.reporter || []).slice(0, 20), comp = (R.groups.component || []).filter(function (x) { return x.total > 0; }), lab = (R.groups.label || []).filter(function (x) { return x.total > 0; });
      V.innerHTML = card('fa-user-pen', 'Quién genera los pedidos <span class="js-mut">· el informante de cada issue en Jira</span>', rep.length ? '<div class="js-chart" style="height:' + Math.max(220, rep.length * 28 + 60) + 'px"><canvas id="jsRep"></canvas></div>' : empty('Sin datos.'))
        + card('fa-table', 'Detalle por informante <span class="js-mut">· tocá una fila para ver sus pedidos</span>', rep.length ? '<div class="js-tblwrap"><table class="js-tbl" id="jo-t"><tr><th>Pidió</th><th class="r">Pedidos</th><th class="r">Creados en el rango</th><th class="r">Sin entregar</th><th class="r">Atrasados</th><th class="r">Entregados</th><th class="r">% a tiempo</th><th class="r">Plazo que da</th><th class="r">Entrega en fin de semana</th></tr>'
          + rep.map(function (p) { return '<tr data-dim="reporter" data-name="' + esc(p.name) + '" style="cursor:pointer"><td><b>' + esc(p.name) + '</b></td><td class="r">' + p.total + '</td><td class="r">' + p.created + '</td><td class="r">' + p.open + '</td><td class="r' + (p.overdue ? ' js-late' : '') + '">' + p.overdue + '</td><td class="r">' + p.resolved + '</td><td class="r">' + num(p.on_time_pct) + (p.on_time_pct == null ? '' : '%') + '</td><td class="r">' + num(p.avg_lead) + (p.avg_lead == null ? '' : ' d') + '</td><td class="r">' + p.due_weekend + '</td></tr>'; }).join('') + '</table></div>' : '')
        + '<div class="js-g2">' + card('fa-cubes', 'Por componente', canvas('jsComp')) + card('fa-tags', 'Por etiqueta', canvas('jsLab')) + '</div>';
      make('jsRep', { type: 'bar', data: { labels: rep.map(function (p) { return p.name; }), datasets: SIT_MAIN.map(function (k) { return { label: SIT[k][0], data: rep.map(function (p) { return p[k]; }), backgroundColor: SIT[k][1] + 'dd', borderRadius: 3 }; }) },
        options: { indexAxis: 'y', interaction: { mode: 'index', intersect: false }, plugins: { legend: legend }, scales: axes(true, true) } });
      donut('jsComp', comp.map(function (x) { return x.name; }), comp.map(function (x) { return x.total; }));
      donut('jsLab', lab.map(function (x) { return x.name; }), lab.map(function (x) { return x.total; }));
      var t = document.getElementById('jo-t');
      if (t) t.addEventListener('click', function (e) { var tr = e.target.closest('tr[data-dim]'); if (tr) goList({ dimf: { dim: tr.dataset.dim, name: tr.dataset.name } }); });
    },
    lista: function () {
      var people = (R.groups.person || []).map(function (p) { return p.name; });
      var chips = [['', 'Todos']].concat(Object.keys(SIT).map(function (k) { return [k, SIT[k][0]]; }));
      var flags = [['due_soon', 'Vencen en 7 días'], ['no_due', 'Sin fecha de entrega'], ['unassigned', 'Sin asignar'], ['awaiting', 'Esperando aprobación'], ['due_weekend', 'Entrega en fin de semana'], ['created_weekend', 'Pedido un fin de semana']];
      var prios = (R.groups.priority || []).map(function (p) { return p.name; }).sort(prioSort);
      var extra = [];
      if (S.range) extra.push(['range', S.range.label]);
      if (S.dayf) extra.push(['dayf', S.dayf.label]);
      if (S.dimf) extra.push(['dimf', DIMS[S.dimf.dim] + ': ' + S.dimf.name]);
      V.innerHTML = '<div class="card"><div class="js-filt" id="jl-sit">' + chips.map(function (c) { return '<button type="button" class="js-chip' + (S.sit === c[0] && !S.flag ? ' on' : '') + '" data-sit="' + c[0] + '">' + esc(c[1]) + '</button>'; }).join('')
        + flags.map(function (f) { return '<button type="button" class="js-chip' + (S.flag === f[0] ? ' on' : '') + '" data-flag="' + f[0] + '">' + esc(f[1]) + '</button>'; }).join('') + '</div>'
        + '<div class="js-ctl"><label class="js-lbl">Buscar<input type="search" id="jl-q" value="' + esc(S.q) + '" placeholder="Clave o título"></label>'
        + '<label class="js-lbl">Responsable<select id="jl-who"><option value="">Todas las personas</option>' + people.map(function (p) { return '<option' + (p === S.who ? ' selected' : '') + '>' + esc(p) + '</option>'; }).join('') + '</select></label>'
        + '<label class="js-lbl">Prioridad<select id="jl-prio"><option value="">Todas</option>' + prios.map(function (p) { return '<option' + (p === S.prio ? ' selected' : '') + '>' + esc(p) + '</option>'; }).join('') + '</select></label>'
        + extra.map(function (x) { return '<button type="button" class="js-chip on" data-clear="' + x[0] + '" title="Quitar este filtro">' + esc(x[1]) + ' <i class="fa-solid fa-xmark"></i></button>'; }).join('') + '</div>'
        + '<div class="js-count" id="jl-n"></div><div class="js-tblwrap" id="jl-t"></div></div>';
      var cols = [['key', 'Clave'], ['summary', 'Título'], ['situation', 'Situación'], ['assignee', 'Responsable'], ['reporter', 'Pidió'], ['priority', 'Prioridad'], ['created', 'Creado'], ['due', 'Fecha de entrega'], ['lead', 'Plazo'], ['delivered_at', 'Entregado'], ['res_days', 'Días']];
      var draw = function () {
        var q = S.q.toLowerCase(), list = R.issues.filter(function (i) {
          if (S.sit && i.situation !== S.sit) return false;
          if (S.flag === 'due_soon' && !(isOpen(i) && i.due && i.due >= R.today && i.due <= addDays(R.today, 7))) return false;
          if (S.flag === 'no_due' && !(isOpen(i) && !i.due)) return false;
          if (S.flag === 'unassigned' && !(isOpen(i) && !i.assignee)) return false;
          if (S.flag === 'awaiting' && !i.awaiting) return false;
          if (S.flag === 'due_weekend' && !i.due_weekend) return false;
          if (S.flag === 'created_weekend' && !i.created_weekend) return false;
          if (S.prio && i.priority !== S.prio) return false;
          if (S.range && !inB(MX[S.range.mx].get(i), S.range.b)) return false;
          if (S.dayf && (i[S.dayf.field] || '').slice(0, 10) !== S.dayf.date) return false;
          if (S.dimf && !dimMatch(i, S.dimf)) return false;
          if (S.who && (i.assignee || 'Sin asignar') !== S.who) return false;
          return !q || (i.key + ' ' + i.summary).toLowerCase().indexOf(q) >= 0;
        });
        if (S.sort) list.sort(function (a, b) { var x = a[S.sort], y = b[S.sort]; x = x == null ? '' : x; y = y == null ? '' : y; return (x > y ? 1 : x < y ? -1 : 0) * S.dir; });
        document.getElementById('jl-n').textContent = list.length === 1 ? '1 issue' : list.length + ' issues';
        var cell = function (i, c) {
          if (c === 'key') return keyLink(i.key);
          if (c === 'situation') return sitTag(i.situation) + (i.late ? ' <span class="js-late">' + i.late + ' d</span>' : '') + (i.awaiting ? ' <span class="js-tag" style="--c:#a78bfa" title="Entregado, todavía sin aprobar"><i class="fa-solid fa-stamp"></i>esperando aprobación</span>' : '');
          if (c === 'created' || c === 'resolved' || c === 'delivered_at') return esc(day((i[c] || '').slice(0, 10)));
          if (c === 'due') return esc(day(i.due)) + (i.due_weekend ? ' <i class="fa-solid fa-calendar-week" style="color:#f59e0b" title="Cae en fin de semana"></i>' : '');
          if (c === 'lead') return i.lead == null ? '' : i.lead + ' d';
          if (c === 'reporter') return esc(i.reporter || '');
          if (c === 'res_days') return num(i.res_days);
          if (c === 'assignee') return esc(i.assignee || 'Sin asignar');
          return esc(i[c]);
        };
        document.getElementById('jl-t').innerHTML = list.length ? '<table class="js-tbl"><tr>' + cols.map(function (c) { return '<th data-sort="' + c[0] + '"' + (['res_days'].indexOf(c[0]) >= 0 ? ' class="r"' : '') + '>' + c[1] + (S.sort === c[0] ? (S.dir > 0 ? ' ▲' : ' ▼') : '') + '</th>'; }).join('') + '</tr>'
          + list.slice(0, S.shown).map(function (i) { return '<tr>' + cols.map(function (c) { return '<td' + (c[0] === 'res_days' ? ' class="r"' : '') + '>' + cell(i, c[0]) + '</td>'; }).join('') + '</tr>'; }).join('') + '</table>'
          + (list.length > S.shown ? '<div style="text-align:center;padding:12px"><button type="button" class="js-btn" id="jl-more">Ver ' + Math.min(100, list.length - S.shown) + ' más</button></div>' : '')
          : empty('Ningún issue con esos filtros.');
      };
      document.getElementById('jl-sit').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        S.sit = b.dataset.sit || ''; S.flag = b.dataset.flag || ''; if (S.flag) S.sit = ''; S.shown = 100;
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); }); draw();
      });
      document.getElementById('jl-q').addEventListener('input', function (e) { S.q = e.target.value; S.shown = 100; draw(); });
      document.getElementById('jl-who').addEventListener('change', function (e) { S.who = e.target.value; S.shown = 100; draw(); });
      document.getElementById('jl-prio').addEventListener('change', function (e) { S.prio = e.target.value; S.shown = 100; draw(); });
      V.querySelectorAll('[data-clear]').forEach(function (b) { b.addEventListener('click', function () { S[b.dataset.clear] = null; b.remove(); S.shown = 100; draw(); }); });
      document.getElementById('jl-t').addEventListener('click', function (e) {
        var th = e.target.closest('th[data-sort]');
        if (th) { S.dir = S.sort === th.dataset.sort ? -S.dir : 1; S.sort = th.dataset.sort; draw(); return; }
        if (e.target.closest('#jl-more')) { S.shown += 100; draw(); }
      });
      draw();
    }
  };
  var addDays = function (d, n) { var t = new Date(d + 'T12:00:00'); t.setDate(t.getDate() + n); return t.toISOString().slice(0, 10); };
  var show = function (tab) {
    S.tab = tab;
    charts.forEach(function (c) { c.destroy(); }); charts = [];
    document.querySelectorAll('#js-tabs [data-tab]').forEach(function (b) { var on = b.dataset.tab === tab; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    try { localStorage.setItem('g7_js_tab', tab); } catch (e) {}
    VIEWS[tab]();
  };
  var goList = function (f) { S.sit = f.sit || ''; S.flag = f.flag || ''; S.who = f.who || ''; S.prio = f.prio || ''; S.range = f.range || null; S.dayf = f.dayf || null; S.dimf = f.dimf || null; S.q = ''; S.shown = 100; show('lista'); document.getElementById('js-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' }); };
  document.getElementById('js-tabs').addEventListener('click', function (e) { var b = e.target.closest('[data-tab]'); if (b) show(b.dataset.tab); });
  document.querySelectorAll('.js-sit[data-sit]').forEach(function (b) { b.addEventListener('click', function () { goList({ sit: b.dataset.sit }); }); });
  document.querySelectorAll('.js-pill[data-flag]').forEach(function (b) { b.addEventListener('click', function () { goList({ flag: b.dataset.flag }); }); });
  var first = 'resumen'; try { first = localStorage.getItem('g7_js_tab') || 'resumen'; } catch (e) {}
  show(VIEWS[first] ? first : 'resumen');

  // ── Qué cuenta como entregado (solo admins) ─────────────────
  var dd = document.getElementById('js-deliver'), df = document.getElementById('js-deliver-form'), dOpen = document.getElementById('js-deliver-open');
  if (dd && df && dOpen) {
    dOpen.addEventListener('click', function () { dd.showModal(); });
    dd.addEventListener('click', function (e) { if (e.target === dd || e.target.closest('[data-close]')) dd.close(); });
    df.addEventListener('change', function (e) { if (e.target.name === 'st') df.querySelector('input[value="pick"]').checked = true; });
    df.addEventListener('submit', function (e) {
      e.preventDefault();
      var mode = df.querySelector('input[name="mode"]:checked').value, states = [].map.call(df.querySelectorAll('input[name="st"]:checked:not(:disabled)'), function (c) { return c.value; });
      var b = df.querySelector('button[type="submit"]'); b.disabled = true;
      fetch('?section=jira&deliver=1', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-JS': '1' }, body: JSON.stringify({ mode: mode, states: states }) })
        .then(function (r) { return r.json(); }).then(function (j) { if (j.error) throw new Error(j.error); location.reload(); })
        .catch(function (er) { b.disabled = false; alert(er.message || 'No se pudo guardar.'); });
    });
  }

  // ── Exportar ───────────────────────────────────────────────
  var dlg = document.getElementById('js-export'), form = document.getElementById('js-export-form');
  if (dlg && form) {
    document.getElementById('js-export-open').addEventListener('click', function () { dlg.showModal(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.closest('[data-close]')) dlg.close(); });
    var sync = function () {
      var iss = form.querySelector('input[name="sec[]"][value="issues"]');
      document.getElementById('js-ex-issues').disabled = !iss.checked;
      form.target = form.fmt.value === 'pdf' ? '_blank' : '';
    };
    form.addEventListener('change', sync);
    form.addEventListener('click', function (e) {
      var a = e.target.closest('[data-all],[data-none]'); if (!a) return;
      form.querySelectorAll('input[name="' + (a.dataset.all || a.dataset.none) + '"]').forEach(function (c) { c.checked = !!a.dataset.all; }); sync();
    });
    form.addEventListener('submit', function (e) {
      if (!form.querySelector('input[name="sec[]"]:checked')) { e.preventDefault(); alert('Elegí al menos una parte del informe.'); return; }
      setTimeout(function () { dlg.close(); }, 300);
    });
    sync();
  }
})();
