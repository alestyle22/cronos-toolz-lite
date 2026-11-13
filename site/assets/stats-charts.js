/* assets/stats-charts.js — Gráficos de stats.php (Chart.js), sin código en línea (CSP estricta, SEC-24). Los datos llegan en #sts-chart-data. */
const STS_DATA=JSON.parse(document.getElementById('sts-chart-data').textContent);   // datos de PHP (stats.php)
const BRAND='#ff6600',GRID='rgba(255,255,255,0.04)',TEXT='#52525b';
Chart.defaults.color=TEXT;Chart.defaults.borderColor=GRID;Chart.defaults.font.family='Inter';Chart.defaults.font.size=11;
function makeLine(id,labels,data,color){const el=document.getElementById(id);if(!el)return;new Chart(el,{type:'line',data:{labels,datasets:[{data,borderColor:color||BRAND,backgroundColor:(color||BRAND).slice(0,7)+'22',fill:true,tension:.4,pointRadius:3,pointBackgroundColor:color||BRAND,borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:GRID},ticks:{maxTicksLimit:10}},y:{grid:{color:GRID},beginAtZero:true}}}});}
function makeBar(id,labels,data,color){const el=document.getElementById(id);if(!el)return;new Chart(el,{type:'bar',data:{labels,datasets:[{data,backgroundColor:(color||BRAND)+'99',borderColor:color||BRAND,borderWidth:1,borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:GRID},ticks:{maxTicksLimit:12}},y:{grid:{color:GRID},beginAtZero:true}}}});}
function makeDoughnut(id,labels,data,colors){const el=document.getElementById(id);if(!el||!data.length)return;new Chart(el,{type:'doughnut',data:{labels,datasets:[{data,backgroundColor:colors,borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{font:{size:10},boxWidth:10,padding:8}}}}});}

const daysData=STS_DATA.daysData;
const hoursRaw=STS_DATA.hoursRaw;
const dowData=STS_DATA.dowData;
const postsData=STS_DATA.postsData;
const actTypes=STS_DATA.actTypes;
const contentDayData=STS_DATA.contentDayData;
const contentTypeData=STS_DATA.contentTypeData;

if(document.getElementById('chartDays')){const labels=daysData.map(d=>new Date(d.day).toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit'}));makeLine('chartDays',labels,daysData.map(d=>d.total));}
if(document.getElementById('chartHours')){const arr=Array(24).fill(0);hoursRaw.forEach(h=>arr[parseInt(h.hour)]=parseInt(h.total));makeBar('chartHours',Array.from({length:24},(_,i)=>String(i).padStart(2,'0')+'h'),arr);}
if(document.getElementById('chartDow')){const arr=Array(7).fill(0);dowData.forEach(d=>arr[parseInt(d.dow)-1]=parseInt(d.total));makeBar('chartDow',['Dom','Lun','Mar','Mie','Jue','Vie','Sab'],arr,'#60a5fa');}
if(document.getElementById('chartPosts')){const labels=postsData.map(d=>new Date(d.day).toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit'}));makeLine('chartPosts',labels,postsData.map(d=>d.total),'#4ade80');}
if(document.getElementById('chartActivity')&&actTypes.length){makeDoughnut('chartActivity',actTypes.map(a=>a.type),actTypes.map(a=>a.cnt),['#ff6600','#4ade80','#60a5fa','#a78bfa','#f59e0b','#ec4899']);}
if(document.getElementById('chartContentDay')){const labels=contentDayData.map(d=>new Date(d.day).toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit'}));makeBar('chartContentDay',labels,contentDayData.map(d=>d.total),'#a78bfa');}
if(document.getElementById('chartContentType')&&contentTypeData.length){const tc={promo:'#ff6600',launch:'#a78bfa',paid:'#4ade80',social:'#60a5fa'};makeDoughnut('chartContentType',contentTypeData.map(a=>a.type),contentTypeData.map(a=>a.cnt),contentTypeData.map(a=>tc[a.type]||'#52525b'));}
// Landings section chart
const landingDaysRaw=STS_DATA.landingDaysRaw;
if(document.getElementById('chartLandingDays')){
  const daysLimit=STS_DATA.daysLimit;
  const today=new Date();const dayMap={};
  landingDaysRaw.forEach(r=>dayMap[r.day]=parseInt(r.total));
  const labels=[],vals=[];
  for(let i=daysLimit-1;i>=0;i--){const d=new Date(today);d.setDate(d.getDate()-i);const k=d.toISOString().slice(0,10);labels.push(d.getDate()+'/'+(d.getMonth()+1));vals.push(dayMap[k]||0);}
  const el=document.getElementById('chartLandingDays');
  new Chart(el,{type:'bar',data:{labels,datasets:[{data:vals,backgroundColor:'rgba(52,211,153,.45)',borderColor:'#34d399',borderWidth:1,borderRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>`${ctx.parsed.y} vistas`}}},scales:{x:{grid:{color:GRID},ticks:{color:TEXT,maxTicksLimit:15}},y:{grid:{color:GRID},ticks:{color:TEXT},beginAtZero:true}}}});
}

// ── Funciones de toda la página, antes código en línea (CSP estricta, SEC-24) ──
function stsSubmit(el){ el.form.submit(); }
function stsGo(url){ location.href = url; }
/** Confirma antes de seguir un link (g7-on cancela el clic si devuelve false). */
function stsConfirm(msg){ return confirm(msg); }
