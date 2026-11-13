/* landing-gen/stats.js — Estadísticas de landings (antes en línea en stats.php; CSP estricta, SEC-24 fase 2). Datos de PHP en #lgs-data. */
let currentLandingId = JSON.parse(document.getElementById('lgs-data').textContent).selectedId;
let currentDays      = 30;
let chartDays        = null;

const PLATFORM_ICONS = {casino:'🎲',sport:'⚽',other:'📄'};
const DEV_ICONS = {desktop:'🖥️',mobile:'📱',tablet:'📟'};
const COUNTRY_FLAGS = {'Argentina':'🇦🇷','Uruguay':'🇺🇾','Chile':'🇨🇱','Brasil':'🇧🇷','Colombia':'🇨🇴','México':'🇲🇽','Peru':'🇵🇪','Venezuela':'🇻🇪','España':'🇪🇸','United States':'🇺🇸','United Kingdom':'🇬🇧'};

function changeLanding(id){ currentLandingId=parseInt(id)||0; loadStats(); }
function setDays(d,btn){ currentDays=d; document.querySelectorAll('.day-btn').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); loadStats(); }

function barHtml(label,cnt,max,flag){
  const pct=max>0?Math.round(cnt/max*100):0;
  return`<div class="bar-item">
    <div class="bar-label">${flag?`<span class="flag">${flag}</span>`:''}${esc(label)}</div>
    <div class="bar-track"><div class="bar-fill" style="width:${pct}%"></div></div>
    <div class="bar-cnt">${cnt}</div>
  </div>`;
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

async function loadStats(){
  document.getElementById('kpi-row').innerHTML='<div class="loading"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>';

  const res  = await fetch('', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'stats', landing_id:currentLandingId, days:currentDays})});
  const data = await res.json();

  // KPIs
  document.getElementById('kpi-row').innerHTML=`
    <div class="kpi"><div class="kpi-label"><i class="fa-solid fa-eye"></i>Total vistas</div><div class="kpi-val">${data.total.toLocaleString()}</div><div class="kpi-sub">últimos ${currentDays} días</div></div>
    <div class="kpi"><div class="kpi-label"><i class="fa-solid fa-user"></i>Visitantes únicos</div><div class="kpi-val">${data.unique.toLocaleString()}</div><div class="kpi-sub">por IP</div></div>
    <div class="kpi"><div class="kpi-label"><i class="fa-solid fa-calendar-day"></i>Hoy</div><div class="kpi-val">${data.today.toLocaleString()}</div><div class="kpi-sub">visitas del día</div></div>
    <div class="kpi"><div class="kpi-label"><i class="fa-solid fa-arrow-trend-up"></i>Promedio/día</div><div class="kpi-val">${currentDays>0?Math.round(data.total/currentDays):0}</div><div class="kpi-sub">vistas/día</div></div>
  `;

  // Chart por día
  const labels=[]; const vals=[];
  // Fill all days in range
  const today=new Date(); const dayMap={};
  data.by_day.forEach(r=>dayMap[r.d]=parseInt(r.cnt));
  for(let i=currentDays-1;i>=0;i--){
    const d=new Date(today); d.setDate(d.getDate()-i);
    const key=d.toISOString().slice(0,10);
    labels.push(d.getDate()+'/'+(d.getMonth()+1));
    vals.push(dayMap[key]||0);
  }
  if(chartDays){chartDays.destroy();}
  chartDays=new Chart(document.getElementById('chart-days'),{
    type:'bar',
    data:{labels,datasets:[{data:vals,backgroundColor:'rgba(255,102,0,.5)',borderColor:'#ff6600',borderWidth:1,borderRadius:3}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>`${ctx.parsed.y} vistas`}}},
      scales:{x:{ticks:{color:'#52525b',font:{size:10}},grid:{color:'#1a1a1a'}},y:{ticks:{color:'#52525b',font:{size:10},stepSize:1},grid:{color:'#1a1a1a'},beginAtZero:true}}}
  });

  // Países
  const maxC=data.by_country[0]?.cnt||1;
  document.getElementById('list-countries').innerHTML=data.by_country.length
    ? data.by_country.map(r=>barHtml(r.country,parseInt(r.cnt),maxC,COUNTRY_FLAGS[r.country]||'🌍')).join('')
    : '<div class="empty"><i class="fa-solid fa-globe"></i>Sin datos de países</div>';

  // Ciudades
  const maxCi=data.by_city[0]?.cnt||1;
  document.getElementById('list-cities').innerHTML=data.by_city.length
    ? data.by_city.map(r=>barHtml(`${r.city}, ${r.country}`,parseInt(r.cnt),maxCi,'📍')).join('')
    : '<div class="empty"><i class="fa-solid fa-map-pin"></i>Sin datos de ciudades</div>';

  // Referrers
  const maxR=data.by_referrer[0]?.cnt||1;
  const refIcon={'Directo':'🔗','google.com':'🔍','facebook.com':'📘','instagram.com':'📸','twitter.com':'🐦','t.co':'🐦','whatsapp.com':'💬','telegram.org':'✈️','tiktok.com':'🎵'};
  document.getElementById('list-referrers').innerHTML=data.by_referrer.length
    ? data.by_referrer.map(r=>barHtml(r.src,parseInt(r.cnt),maxR,refIcon[r.src]||'🌐')).join('')
    : '<div class="empty"><i class="fa-solid fa-link"></i>Sin datos de fuentes</div>';

  // Dispositivos
  const total=data.by_device.reduce((s,r)=>s+parseInt(r.cnt),0)||1;
  const devMap={};data.by_device.forEach(r=>devMap[r.device]=parseInt(r.cnt));
  document.getElementById('dev-wrap').innerHTML=`
    <div class="dev-row">
      ${['desktop','mobile','tablet'].map(d=>`<div class="dev-item">
        <div class="dev-icon">${DEV_ICONS[d]}</div>
        <div class="dev-pct">${Math.round((devMap[d]||0)/total*100)}%</div>
        <div class="bar-track" style="width:100%;margin:4px 0"><div class="bar-fill" style="width:${Math.round((devMap[d]||0)/total*100)}%"></div></div>
        <div class="dev-label">${d==='desktop'?'PC':d==='mobile'?'Móvil':'Tablet'}</div>
        <div style="font-size:10px;color:var(--g7-text-4)">${devMap[d]||0} visitas</div>
      </div>`).join('')}
    </div>`;

  // Ranking landings
  const rankWid=document.getElementById('landing-rank-wid');
  const rankCont=document.getElementById('landing-rank');
  if(!currentLandingId && data.by_landing?.length){
    rankWid.style.display='block';
    rankCont.innerHTML=data.by_landing.map(l=>`<div class="land-row">
      <div class="land-name">${esc(l.name)}</div>
      <span class="land-plat">${esc(l.platform)}</span>
      <div class="land-cnt">${parseInt(l.cnt).toLocaleString()}</div>
      ${l.public_url?`<a href="${esc(l.public_url)}" target="_blank" class="land-link" title="Ver landing"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>`:''}
      <a href="?id=${l.id}" class="land-link" title="Ver estadísticas" style="margin-left:4px"><i class="fa-solid fa-chart-bar"></i></a>
    </div>`).join('');
  } else { rankWid.style.display='none'; }
}

loadStats();
