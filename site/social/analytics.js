/* social/analytics.js — Social Studio · Analytics (#analytics). It uses the helpers of app.js ($, esc, api, toast, S…) and Chart.js.
   Range (presets or custom), comparison (previous period, same one the year before or none), filters (brand or client, network),
   metrics with change, chart of the period vs the comparison, accounts and networks, top posts and goals. Only the metrics
   that Meta's API actually gives for the connected accounts: if one does not come, it is not shown. No inline code (CSP). */
'use strict';
const anNet = (k, color) => { const n = S.meta.networks[k]; return n ? `<i class="fa-brands ${n.icon}"${color ? ` style="color:${n.color}"` : ''} title="${esc(n.name)}"></i>` : ''; };
const AN = { f: { preset: '30', from: '', to: '', compare: 'prev', group: '', network: '' }, d: null, metric: '', chart: null, goals: null };
const AN_PRESETS = [['7', '7 días'], ['30', '30 días'], ['90', '90 días'], ['month', 'Este mes'], ['prev_month', 'Mes pasado'], ['year', 'Este año'], ['custom', 'Elegir fechas']];
const anNum = v => v === null || v === undefined ? '—' : Math.abs(v) >= 10000 ? (v / 1000).toFixed(Math.abs(v) >= 100000 ? 0 : 1).replace('.', ',') + ' mil' : Number(v).toLocaleString('es-AR');
const anDelta = d => d === null || d === undefined ? '<span class="an-d">sin comparación</span>' : `<span class="an-d ${d > 0 ? 'up' : d < 0 ? 'down' : ''}"><i class="fa-solid ${d > 0 ? 'fa-arrow-trend-up' : d < 0 ? 'fa-arrow-trend-down' : 'fa-minus'}"></i> ${d > 0 ? '+' : ''}${String(d).replace('.', ',')}%</span>`;
const anDay = s => { const d = parse(s); return d.getDate() + ' ' + MONTHS[d.getMonth()].slice(0, 3); };

function anRange() {
  const t = new Date(), y = t.getFullYear(), m = t.getMonth(), p = AN.f.preset;
  if (p === 'custom' && AN.f.from && AN.f.to) return [AN.f.from, AN.f.to];
  if (p === 'month') return [ymd(new Date(y, m, 1)), ymd(t)];
  if (p === 'prev_month') return [ymd(new Date(y, m - 1, 1)), ymd(new Date(y, m, 0))];
  if (p === 'year') return [ymd(new Date(y, 0, 1)), ymd(t)];
  const n = Number(p) || 30; return [ymd(addDays(t, -(n - 1))), ymd(t)];
}
async function openAnalytics() {
  const box = $('#ss-analytics');
  if (!box.dataset.ready) { drawAnShell(); box.dataset.ready = '1'; }
  await anLoad();
}
function drawAnShell() {
  const M = S.meta, groups = M.group === 'client' ? M.clients : M.brands;
  $('#ss-analytics').innerHTML = `<div class="an-bar">
      <div class="an-presets">${AN_PRESETS.map(([k, l]) => `<button type="button" data-an-preset="${k}"${AN.f.preset === k ? ' class="on"' : ''}>${l}</button>`).join('')}</div>
      <span class="an-dates" id="an-dates" ${AN.f.preset === 'custom' ? '' : 'hidden'}><input type="date" class="ss-in" id="an-from" aria-label="Desde"> a <input type="date" class="ss-in" id="an-to" aria-label="Hasta"></span>
      <select class="ss-in" id="an-compare" aria-label="Comparar con"><option value="prev">vs. período anterior</option><option value="year">vs. mismo período del año pasado</option><option value="none">Sin comparar</option></select>
      <select class="ss-in" id="an-group" aria-label="${groupWord()}"><option value="">${M.group === 'client' ? 'Todos los clientes' : 'Todas las marcas'}</option>${groups.map(g => `<option value="${g.id}">${esc(g.name)}</option>`).join('')}</select>
      <select class="ss-in" id="an-net" aria-label="Red"><option value="">Todas las redes</option>${(S.meta.connectable || ['instagram', 'facebook']).map(k => `<option value="${k}">${esc(S.meta.networks[k] ? S.meta.networks[k].name : k)}</option>`).join('')}</select>
      <span class="sp"></span><span class="an-sync" id="an-sync-at"></span>
      <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="an-sync"><i class="fa-solid fa-rotate"></i> Actualizar datos</button>
      ${can('config') ? '<button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="an-goals"><i class="fa-solid fa-bullseye"></i> Objetivos</button>' : ''}
    </div><div id="an-body"></div>`;
  $('#an-compare').value = AN.f.compare;
  [['an-compare', 'compare'], ['an-group', 'group'], ['an-net', 'network']].forEach(([id, k]) => $('#' + id).addEventListener('change', e => { AN.f[k] = e.target.value; anLoad(); }));
  ['an-from', 'an-to'].forEach(id => $('#' + id).addEventListener('change', () => { AN.f.from = $('#an-from').value; AN.f.to = $('#an-to').value; if (AN.f.from && AN.f.to) anLoad(); }));
}
async function anLoad() {
  const [from, to] = anRange();
  if ($('#an-from')) { $('#an-from').value = from; $('#an-to').value = to; }
  $('#an-body').innerHTML = '<div class="ss-skel" style="grid-template-columns:repeat(4,1fr)"><span style="height:110px"></span><span style="height:110px"></span><span style="height:110px"></span><span style="height:110px"></span></div>';
  const f = { from, to, compare: AN.f.compare, network: AN.f.network };
  if (AN.f.group) f[S.meta.group === 'client' ? 'client' : 'brand'] = Number(AN.f.group);
  try { AN.d = await api('an_report', { filters: f }); }
  catch (e) { $('#an-body').innerHTML = `<div class="ss-empty"><div class="art"><i class="fa-solid fa-chart-line"></i></div><h3>No se pudo armar el tablero</h3><p>${esc(e.message)}</p></div>`; return; }
  drawAn();
}
function drawAn() {
  const D = AN.d;
  $('#an-sync-at').textContent = D.last_sync ? 'Datos de Meta del ' + fmtWhen(D.last_sync) : '';
  if (!D.has_accounts) {
    $('#an-body').innerHTML = `<div class="ss-card"><div class="ss-empty"><div class="art"><i class="fa-solid fa-chart-line"></i></div><h3>No hay cuentas conectadas${AN.f.group || AN.f.network ? ' con estos filtros' : ''}</h3><p>Analytics muestra las métricas reales de las páginas de Facebook y las cuentas de Instagram que conectes. No hay datos de ejemplo.</p><a class="ss-btn" href="#cuentas"><i class="fa-solid fa-plug"></i> Ir a Cuentas</a></div></div>`;
    return;
  }
  if (!D.kpis.length && !D.top.length) {
    $('#an-body').innerHTML = `<div class="ss-card"><div class="ss-empty"><div class="art"><i class="fa-regular fa-hourglass-half"></i></div><h3>Todavía no hay datos en este período</h3><p>Las métricas se traen de Meta una vez por día (y Meta las demora hasta 48 h). Tocá <b>Actualizar datos</b> para traerlas ahora.${D.last_sync ? '' : ' Esta cuenta todavía no se sincronizó nunca.'}</p></div></div>`;
    return;
  }
  const cmpTxt = D.compare === 'none' ? '' : `vs. ${anDay(D.prev_from)} – ${anDay(D.prev_to)}${D.compare === 'year' ? ' del año pasado' : ''}`;
  const eng = D.engagement;
  const kp = D.kpis.map(k => `<button type="button" class="an-kpi${AN.metric === k.key ? ' on' : ''}" data-an-metric="${k.key}" title="${esc(k.help)}">
      <small>${esc(k.label)}</small><b>${anNum(k.value)}</b>${D.compare === 'none' ? '' : anDelta(k.delta)}${D.compare !== 'none' && k.prev !== null ? `<em>antes: ${anNum(k.prev)}</em>` : ''}
      ${k.key === 'followers' && D.growth !== null ? `<em class="${D.growth >= 0 ? 'up' : 'down'}">${D.growth >= 0 ? '+' : ''}${anNum(D.growth)} en el período</em>` : ''}</button>`).join('')
    + (eng.value !== null ? `<div class="an-kpi"><small>Engagement</small><b>${String(eng.value).replace('.', ',')}%</b>${D.compare === 'none' ? '' : anDelta(eng.delta)}<em>interacciones sobre ${esc(eng.base)}</em></div>` : '');
  const keys = Object.keys(D.series);
  if (!AN.metric || !keys.includes(AN.metric)) AN.metric = keys.includes('interactions') ? 'interactions' : keys.includes('reach') ? 'reach' : keys[0] || '';
  const accCols = Object.keys(D.catalog).filter(k => D.accounts.some(a => a.totals[k] !== undefined));
  const accs = `<div class="ss-tblwrap"><table class="an-tbl"><tr><th>Cuenta</th>${accCols.map(k => `<th class="r">${esc(D.catalog[k].label)}</th>`).join('')}<th class="r">Engagement</th></tr>
    ${D.accounts.map(a => `<tr><td><span class="an-acc">${anNet(a.network, true)} ${esc(a.name)}${a.status !== 'ok' ? ' <span class="ss-pill" style="--c:#f59e0b">' + (a.status === 'expired' ? 'venció' : a.status) + '</span>' : ''}</span></td>
      ${accCols.map(k => `<td class="r">${anNum(a.totals[k])}${D.compare !== 'none' && a.totals[k] !== undefined && a.prev[k] ? `<small>${anDelta(Math.round((a.totals[k] - a.prev[k]) / Math.abs(a.prev[k]) * 1000) / 10)}</small>` : ''}</td>`).join('')}
      <td class="r">${a.engagement === null ? '—' : String(a.engagement).replace('.', ',') + '%'}</td></tr>`).join('')}</table></div>`;
  const nets = Object.keys(D.networks);
  const top = D.top.length ? `<div class="an-posts">${D.top.map(p => `<a class="an-post" href="${esc(p.permalink)}" target="_blank" rel="noopener">
      <span class="th">${p.thumb ? `<img src="${esc(p.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : '<i class="fa-regular fa-image"></i>'}<span class="net">${anNet(p.network, false)}</span></span>
      <span class="x"><small>${esc(p.account)} · ${esc(fmtWhen(p.published_at))}${p.post_id ? ' · <b>desde Social Studio</b>' : ''}</small><span class="cap">${esc(p.caption || 'Sin texto')}</span>
      <span class="ms">${Object.entries(p.metrics).map(([k, v]) => `<span title="${esc((D.catalog[k] || {}).label || k)}"><i class="fa-solid ${{ reach: 'fa-users', views: 'fa-eye', likes: 'fa-heart', comments: 'fa-comment', shares: 'fa-share', saves: 'fa-bookmark', interactions: 'fa-bolt' }[k] || 'fa-circle'}"></i> ${anNum(v)}</span>`).join('')}${p.engagement !== null ? `<span title="Engagement"><i class="fa-solid fa-percent"></i> ${String(p.engagement).replace('.', ',')}</span>` : ''}</span></span></a>`).join('')}</div>`
    : '<div class="ss-empty sm"><p>No hay publicaciones en este período.</p></div>';
  const goals = D.goals.length ? D.goals.map(g => `<div class="an-goal"><div class="t"><b>${esc(g.name)}</b> · ${esc(g.label)} <small>${anNum(g.value)} de ${anNum(g.target)} este mes</small></div>
      <div class="bar"><i style="width:${Math.min(100, g.pct || 0)}%;background:${g.on_track === false ? '#f59e0b' : '#22c55e'}"></i></div><span>${g.pct === null ? 'sin datos' : g.pct + '%'}${g.on_track === false ? ' · viene atrasado' : g.on_track ? ' · en camino' : ''}</span></div>`).join('')
    : `<p class="ss-hint">${can('config') ? 'Todavía no hay objetivos. Se cargan con el botón <b>Objetivos</b> (por marca o cliente, por mes).' : 'Todavía no hay objetivos cargados.'}</p>`;
  $('#an-body').innerHTML = `<p class="an-period"><b>${anDay(D.from)} – ${anDay(D.to)}</b> · ${D.days} días${cmpTxt ? ' · ' + cmpTxt : ''}</p>
    <div class="an-kpis">${kp}</div>
    ${keys.length ? `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-chart-line"></i> ${esc((D.catalog[AN.metric] || {}).label || '')} por día</h3><div class="an-chips">${keys.map(k => `<button type="button" data-an-metric="${k}"${k === AN.metric ? ' class="on"' : ''}>${esc(D.catalog[k].label)}</button>`).join('')}</div></div><div class="an-chart"><canvas id="an-canvas"></canvas></div></section>` : ''}
    <div class="an-g2">
      <section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-layer-group"></i> Cuentas</h3></div>${accs}</section>
      ${nets.length > 1 ? `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-scale-balanced"></i> Comparación entre redes</h3></div><div class="an-chart sm"><canvas id="an-nets"></canvas></div></section>` : `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-bullseye"></i> Objetivos del mes</h3></div>${goals}</section>`}
    </div>
    ${nets.length > 1 ? `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-bullseye"></i> Objetivos del mes</h3></div>${goals}</section>` : ''}
    <section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-trophy"></i> Publicaciones destacadas</h3><span class="ss-hint" style="margin:0">ordenadas por interacciones · ${D.posts_count} en el período</span></div>${top}</section>
    <p class="ss-hint"><i class="fa-solid fa-circle-info"></i> Solo se muestran las métricas que la API de Meta entrega para cada red. Meta las actualiza con hasta 48 h de demora; las de Facebook, una vez por día.</p>`;
  anCharts();
}
function anCharts() {
  if (typeof Chart === 'undefined') return;
  const css = getComputedStyle(document.documentElement), tx = (css.getPropertyValue('--g7-text-4') || '#888').trim(), acc = (css.getPropertyValue('--g7-accent') || '#ff6600').trim();
  const D = AN.d, s = D.series[AN.metric];
  if (AN.chart) { AN.chart.destroy(); AN.chart = null; }
  if (AN.netChart) { AN.netChart.destroy(); AN.netChart = null; }
  const axes = { x: { ticks: { color: tx, maxTicksLimit: 10 }, grid: { display: false } }, y: { ticks: { color: tx }, grid: { color: 'rgba(128,128,128,.12)' }, beginAtZero: true } };
  if (s && $('#an-canvas')) {
    const ds = [{ label: 'Este período', data: s.cur, borderColor: acc, backgroundColor: acc + '22', fill: true, tension: .3, pointRadius: 0, spanGaps: true }];
    if (s.prev) ds.push({ label: D.compare === 'year' ? 'Año anterior' : 'Período anterior', data: s.prev, borderColor: '#94a3b8', borderDash: [5, 4], tension: .3, pointRadius: 0, spanGaps: true });
    AN.chart = new Chart($('#an-canvas'), { type: 'line', data: { labels: D.labels.map(anDay), datasets: ds }, options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { labels: { color: tx, boxWidth: 12 } } }, scales: axes } });
  }
  const nets = Object.keys(D.networks);
  if (nets.length > 1 && $('#an-nets')) {
    const ks = ['reach', 'views', 'interactions', 'new_followers'].filter(k => nets.some(n => D.networks[n][k] !== undefined));
    AN.netChart = new Chart($('#an-nets'), { type: 'bar', data: { labels: ks.map(k => D.catalog[k].label), datasets: nets.map(n => ({ label: S.meta.networks[n] ? S.meta.networks[n].name : n, data: ks.map(k => D.networks[n][k] ?? null), backgroundColor: S.meta.networks[n] && S.meta.networks[n].color !== '#a1a1aa' ? S.meta.networks[n].color : (n === 'x' ? '#71717a' : '#a1a1aa'), borderRadius: 5 })) },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: tx, boxWidth: 12 } } }, scales: axes } });
  }
}
async function anGoals() {
  let g; try { g = (await api('goals')).goals; } catch (e) { return; }
  const M = S.meta, groups = M.group === 'client' ? M.clients : M.brands, scope = M.group === 'client' ? 'client' : 'brand', cat = AN.d ? AN.d.catalog : {};
  const name = x => x.scope_id ? ((x.scope === 'client' ? M.clients : M.brands).find(y => y.id === x.scope_id) || { name: '#' + x.scope_id }).name : 'Todas las cuentas';
  openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-bullseye"></i> Objetivos por mes</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b"><div class="ib-rlist">${g.map(x => `<div class="ib-r"><div><b>${esc(name(x))}</b> · ${esc((cat[x.metric] || {}).label || x.metric)}: <b>${anNum(x.target)}</b> por mes</div><button type="button" class="ss-icon" data-an-gdel="${x.id}" aria-label="Borrar"><i class="fa-solid fa-trash"></i></button></div>`).join('') || '<p class="ss-hint">Todavía no hay objetivos.</p>'}</div>
    <div class="ss-form" style="margin-top:14px">
      <label class="ss-f"><span>${M.group === 'client' ? 'Cliente' : 'Marca'}</span><select class="ss-in" id="ang-scope"><option value="0">Todas las cuentas</option>${groups.map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}</select></label>
      <label class="ss-f"><span>Métrica</span><select class="ss-in" id="ang-metric">${Object.entries(cat).map(([k, m]) => `<option value="${k}">${esc(m.label)}</option>`).join('')}</select></label>
      <label class="ss-f"><span>Objetivo del mes<small>seguidores: cantidad a fin de mes</small></span><input class="ss-in" id="ang-target" type="number" min="1" step="1"></label>
      <div class="ss-f" style="align-self:end"><button type="button" class="ss-btn" data-act="an-gsave"><i class="fa-solid fa-plus"></i> Guardar objetivo</button></div></div></div>`, 'sm');
  AN.goalScope = scope;
}
document.addEventListener('click', async e => {
  const b = e.target.closest('[data-an-preset],[data-an-metric],[data-an-gdel],[data-act^="an-"]'); if (!b) return;
  if (b.dataset.anPreset) { AN.f.preset = b.dataset.anPreset; $$('[data-an-preset]').forEach(x => x.classList.toggle('on', x === b)); $('#an-dates').hidden = AN.f.preset !== 'custom'; if (AN.f.preset !== 'custom' || (AN.f.from && AN.f.to)) anLoad(); return; }
  if (b.dataset.anMetric) { if (!AN.d.series[b.dataset.anMetric]) return; AN.metric = b.dataset.anMetric; drawAn(); return; }
  if (b.dataset.anGdel) { try { await api('goal_delete', { id: Number(b.dataset.anGdel) }); anGoals(); anLoad(); } catch (err) {} return; }
  switch (b.dataset.act) {
    case 'an-sync': b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Trayendo de Meta…';
      try { const d = await api('an_sync'); toast(d.errors.length ? d.errors[0] : `Listo: ${d.accounts} cuenta${d.accounts === 1 ? '' : 's'} actualizada${d.accounts === 1 ? '' : 's'}`, !!d.errors.length); anLoad(); } catch (err) {}
      b.disabled = false; b.innerHTML = '<i class="fa-solid fa-rotate"></i> Actualizar datos'; break;
    case 'an-goals': anGoals(); break;
    case 'an-gsave': try { await api('goal_save', { goal: { scope: AN.goalScope, scope_id: Number($('#ang-scope').value), metric: $('#ang-metric').value, target: Number($('#ang-target').value) } }); toast('Objetivo guardado'); anGoals(); anLoad(); } catch (err) {} break;
  }
});
