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
  var DIMS = { person: 'Persona', type: 'Tipo', priority: 'Prioridad', project: 'Proyecto', status: 'Estado' };
  var METRICS = { total: 'Issues en el informe', created: 'Creados', resolved: 'Entregados', open: 'Abiertos', overdue: 'Atrasados', on_time: 'Entregados a tiempo',
    late_done: 'Entregados tarde', in_progress: 'En curso', not_started: 'Sin empezar', on_time_pct: '% a tiempo', avg_days: 'Días promedio para resolver' };
  var S = { tab: 'resumen', sit: '', flag: '', who: '', q: '', sort: '', dir: 1, shown: 100, dim: 'person', metric: 'total', chart: 'bar', top: '15' };
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
    lista: function () {
      var people = (R.groups.person || []).map(function (p) { return p.name; });
      var chips = [['', 'Todos']].concat(Object.keys(SIT).map(function (k) { return [k, SIT[k][0]]; }));
      var flags = [['due_soon', 'Vencen en 7 días'], ['no_due', 'Sin fecha de entrega'], ['unassigned', 'Sin asignar']];
      V.innerHTML = '<div class="card"><div class="js-filt" id="jl-sit">' + chips.map(function (c) { return '<button type="button" class="js-chip' + (S.sit === c[0] && !S.flag ? ' on' : '') + '" data-sit="' + c[0] + '">' + esc(c[1]) + '</button>'; }).join('')
        + flags.map(function (f) { return '<button type="button" class="js-chip' + (S.flag === f[0] ? ' on' : '') + '" data-flag="' + f[0] + '">' + esc(f[1]) + '</button>'; }).join('') + '</div>'
        + '<div class="js-ctl"><label class="js-lbl">Buscar<input type="search" id="jl-q" value="' + esc(S.q) + '" placeholder="Clave o título"></label>'
        + '<label class="js-lbl">Responsable<select id="jl-who"><option value="">Todas las personas</option>' + people.map(function (p) { return '<option' + (p === S.who ? ' selected' : '') + '>' + esc(p) + '</option>'; }).join('') + '</select></label></div>'
        + '<div class="js-count" id="jl-n"></div><div class="js-tblwrap" id="jl-t"></div></div>';
      var cols = [['key', 'Clave'], ['summary', 'Título'], ['situation', 'Situación'], ['assignee', 'Responsable'], ['type', 'Tipo'], ['priority', 'Prioridad'], ['created', 'Creado'], ['due', 'Entrega'], ['resolved', 'Resuelto'], ['res_days', 'Días']];
      var draw = function () {
        var q = S.q.toLowerCase(), list = R.issues.filter(function (i) {
          if (S.sit && i.situation !== S.sit) return false;
          if (S.flag === 'due_soon' && !(i.cat !== 'done' && i.due && i.due >= R.today && i.due <= addDays(R.today, 7))) return false;
          if (S.flag === 'no_due' && !(i.cat !== 'done' && !i.due)) return false;
          if (S.flag === 'unassigned' && !(i.cat !== 'done' && !i.assignee)) return false;
          if (S.who && (i.assignee || 'Sin asignar') !== S.who) return false;
          return !q || (i.key + ' ' + i.summary).toLowerCase().indexOf(q) >= 0;
        });
        if (S.sort) list.sort(function (a, b) { var x = a[S.sort], y = b[S.sort]; x = x == null ? '' : x; y = y == null ? '' : y; return (x > y ? 1 : x < y ? -1 : 0) * S.dir; });
        document.getElementById('jl-n').textContent = list.length === 1 ? '1 issue' : list.length + ' issues';
        var cell = function (i, c) {
          if (c === 'key') return keyLink(i.key);
          if (c === 'situation') return sitTag(i.situation) + (i.late ? ' <span class="js-late">' + i.late + ' d</span>' : '');
          if (c === 'created' || c === 'resolved') return esc(day((i[c] || '').slice(0, 10)));
          if (c === 'due') return esc(day(i.due));
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
  var goList = function (f) { S.sit = f.sit || ''; S.flag = f.flag || ''; S.who = f.who || ''; S.q = ''; S.shown = 100; show('lista'); document.getElementById('js-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' }); };
  document.getElementById('js-tabs').addEventListener('click', function (e) { var b = e.target.closest('[data-tab]'); if (b) show(b.dataset.tab); });
  document.querySelectorAll('.js-sit[data-sit]').forEach(function (b) { b.addEventListener('click', function () { goList({ sit: b.dataset.sit }); }); });
  document.querySelectorAll('.js-pill[data-flag]').forEach(function (b) { b.addEventListener('click', function () { goList({ flag: b.dataset.flag }); }); });
  var first = 'resumen'; try { first = localStorage.getItem('g7_js_tab') || 'resumen'; } catch (e) {}
  show(VIEWS[first] ? first : 'resumen');

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
