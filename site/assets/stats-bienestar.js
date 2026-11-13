/* assets/stats-bienestar.js — gráficos de Estadísticas › Bienestar (stats.php). Datos anónimos de mood_report() en #bw-data. */
(function () {
  var box = document.getElementById('bw-data'); if (!box || typeof Chart === 'undefined') return;
  var R; try { R = JSON.parse(box.dataset.report || '{}'); } catch (e) { return; }
  var L = R.levels || {}, css = getComputedStyle(document.documentElement);
  var txt = (css.getPropertyValue('--g7-text-4') || '#888').trim(), grid = 'rgba(128,128,128,.12)';
  var lbl = (R.days || []).map(function (d) { var p = d.day.split('-'); return p[2] + '/' + p[1]; });
  var sets = [1, 2, 3, 4, 5].map(function (k) {
    return { type: 'bar', label: L[k] ? L[k].label : k, data: R.days.map(function (d) { return d.dist ? d.dist[k] : null; }),
      backgroundColor: (L[k] ? L[k].color : '#888') + 'cc', borderRadius: 3, stack: 'm', yAxisID: 'y', order: 2 };
  });
  sets.push({ type: 'line', label: 'Promedio', data: R.days.map(function (d) { return d.avg; }), yAxisID: 'y2', borderColor: '#f472b6', backgroundColor: '#f472b6',
    borderWidth: 2.5, tension: .35, pointRadius: 3, spanGaps: true, order: 1 });
  new Chart(document.getElementById('bwDaily'), {
    data: { labels: lbl, datasets: sets },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { labels: { color: txt, boxWidth: 12, font: { size: 11 } } },
        tooltip: { callbacks: { footer: function (it) { var d = R.days[it[0].dataIndex]; return d.n ? d.n + ' respuestas' : 'Pocas respuestas: no se muestra'; } } } },
      scales: { x: { stacked: true, ticks: { color: txt, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 }, grid: { display: false } },
        y: { stacked: true, beginAtZero: true, ticks: { color: txt, precision: 0 }, grid: { color: grid }, title: { display: true, text: 'Respuestas', color: txt } },
        y2: { position: 'right', min: 1, max: 5, ticks: { color: txt, stepSize: 1 }, grid: { display: false }, title: { display: true, text: 'Promedio (1 a 5)', color: txt } } } }
  });
  var lvColor = function (v) { if (v == null) return 'rgba(128,128,128,.25)'; var k = Math.max(1, Math.min(5, Math.round(v))); return L[k] ? L[k].color : '#888'; };
  new Chart(document.getElementById('bwDow'), {
    type: 'bar',
    data: { labels: R.dow.map(function (d) { return d.name.slice(0, 3); }),
      datasets: [{ label: 'Promedio', data: R.dow.map(function (d) { return d.avg; }), backgroundColor: R.dow.map(function (d) { return lvColor(d.avg); }), borderRadius: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false },
        tooltip: { callbacks: { label: function (c) { return c.raw == null ? 'Pocas respuestas' : 'Promedio ' + String(c.raw).replace('.', ',') + ' de 5'; } } } },
      scales: { x: { ticks: { color: txt }, grid: { display: false } }, y: { min: 0, max: 5, ticks: { color: txt, stepSize: 1 }, grid: { color: grid } } } }
  });
})();
