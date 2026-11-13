/* assets/pages-editor.js — Editor de pages.php (Pages Builder), sin código en línea (CSP estricta, SEC-24). Los datos llegan en #pg-cfg y #pg-html. */
const PG_URL  = '/toolz/pages.php';
const PG_CFG = JSON.parse(document.getElementById('pg-cfg').textContent);   // datos de PHP (pages.php)
const PAGE_ID = PG_CFG.id;
let heroUrl   = PG_CFG.heroUrl;
let logoUrl   = PG_CFG.logoUrl;
let bgType    = PG_CFG.bgType;
let bgValue   = PG_CFG.bgValue;

function g(id){return document.getElementById(id);}

// ── TABS ─────────────────────────────────────────────────
function switchTab(name, btn){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.ptab').forEach(b=>b.classList.remove('active'));
  g('tab-'+name).classList.add('active');
  btn.classList.add('active');
}

// ── DEVICE ───────────────────────────────────────────────
let currentDevW = null;
function setDev(w, btn){
  document.querySelectorAll('.dev-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  currentDevW = w;
  scaleIframe();
}
function scaleIframe(){
  const iframe = g('preview-iframe');
  const wrap   = g('canvas-frame');
  const wrapW  = wrap.parentElement.offsetWidth - 32;
  const devW   = currentDevW || 1280;
  iframe.style.width = devW + 'px';
  const scale = Math.min(1, wrapW / devW);
  iframe.style.transform = `scale(${scale})`;
  iframe.style.transformOrigin = 'top left';
  wrap.style.height = (parseInt(iframe.style.height)||900) * scale + 'px';
}

function resizeIframe(){
  const iframe = g('preview-iframe');
  setTimeout(function(){
    try{
      const doc = iframe.contentDocument || iframe.contentWindow?.document;
      if(!doc) return;
      const h = doc.documentElement?.scrollHeight || doc.body?.scrollHeight || 900;
      iframe.style.height = Math.max(h, 700) + 'px';
      scaleIframe();
    } catch(e){ iframe.style.height = '900px'; scaleIframe(); }
  }, 150);
}

// ── BG HELPERS ────────────────────────────────────────────
function setBg(el, val){
  document.querySelectorAll('.bg-swatch').forEach(s=>s.classList.remove('active'));
  el.classList.add('active');
  bgType  = val.startsWith('#') ? 'color' : 'gradient';
  bgValue = val;
  liveUpdate();
}
function setBgColor(val){
  bgType='color'; bgValue=val;
  document.querySelectorAll('.bg-swatch').forEach(s=>s.classList.remove('active'));
  liveUpdate();
}
function buildGrad(){
  const c1=g('g-c1').value, c2=g('g-c2').value, a=g('g-ang').value;
  g('g-preview').style.background = `linear-gradient(${a}deg,${c1},${c2})`;
}
function applyGrad(){
  const c1=g('g-c1').value, c2=g('g-c2').value, a=g('g-ang').value;
  bgType='gradient'; bgValue=`linear-gradient(${a}deg,${c1},${c2})`;
  document.querySelectorAll('.bg-swatch').forEach(s=>s.classList.remove('active'));
  liveUpdate();
}

// ── TERMS ────────────────────────────────────────────────
function addTerm(){
  const list=g('terms-list');
  const n=list.querySelectorAll('.term-card').length+1;
  const div=document.createElement('div');div.className='term-card';
  div.innerHTML=`<div class="term-head"><span style="font-size:10px;font-weight:700;color:var(--g7-text-4)">Término ${n}</span><button class="term-del" data-on-click="pgTermDel" data-click-1="this"><i class="fa-solid fa-xmark"></i></button></div><input class="prop-input" placeholder="Título..." data-on-input="liveUpdate" style="margin-bottom:5px"><textarea class="prop-input" placeholder="Contenido..." rows="3" data-on-input="liveUpdate"></textarea>`;
  list.appendChild(div);
}
function getTerms(){
  return Array.from(g('terms-list').querySelectorAll('.term-card')).map(card=>{
    const ins=card.querySelectorAll('.prop-input');
    return{title:ins[0]?.value||'',body:ins[1]?.value||''};
  }).filter(t=>t.title.trim());
}

// ── UPLOAD ────────────────────────────────────────────────
async function uploadImg(input, type){
  const file=input.files[0];if(!file)return;
  const fd=new FormData();fd.append('f',file);fd.append('t',type);
  const r=await fetch(PG_URL,{method:'POST',body:fd});
  const d=await r.json();
  if(d.ok){
    if(type==='hero'){heroUrl=d.url;const th=g('hero-thumb');th.src=d.url;th.style.display='block';}
    else if(type==='logo'){logoUrl=d.url;const th=g('logo-thumb');th.src=d.url;th.style.display='block';}
    else if(type==='bg'){bgType='image';bgValue=d.url;}
    liveUpdate();
  }
  input.value='';
}
function dropImg(e, type){e.preventDefault();const f=e.dataTransfer.files[0];if(!f)return;uploadImg({files:[f],value:''},type);}

// ── LIVE PREVIEW ─────────────────────────────────────────
let updateTimer;
function liveUpdate(){
  clearTimeout(updateTimer);
  updateTimer=setTimeout(renderPreview, 300);
}

function getData(){
  return{
    title:g('p-title')?.value||'',
    subtitle:g('p-sub')?.value||'',
    badge_text:g('p-badge')?.value||'',
    cta_text:g('p-cta')?.value||'',
    cta_url:g('p-url')?.value||'#',
    cta_bottom_text:g('p-bcta')?.value||'',
    cta_bottom_url:g('p-url')?.value||'#',
    hero_img_url:heroUrl,logo_url:logoUrl,
    bg_type:bgType,bg_value:bgValue,
    primary_color:g('p-color-hex')?.value||'#f04e23',
    bottom_title:g('p-btitle')?.value||'',
    bottom_subtitle:g('p-bsub')?.value||'',
    terms:getTerms(),
    published:1,slug:'',
    bg_position:'center',bg_size:'cover',show_dot_grid:1,
    hero_glow_color:'#f04e23',hero_glow_opacity:0.2,
    hero_gradient_left:'rgba(0,90,180,0.18)',hero_gradient_right:'rgba(240,78,35,0.10)',
    footer_bg:'linear-gradient(135deg,#0d1e31,#0b1622)',footer_glow_color:'#f04e23',
    show_phone_frame:1,phone_frame_color:'#0d1b2a',
  };
}

async function renderPreview(){
  const d=getData();
  // Get the rendered HTML from server
  const r=await fetch(PG_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'preview',id:PAGE_ID,...d,slug:PG_CFG.slug})});
  const res=await r.json();
  if(res.html){
    const iframe=g('preview-iframe');
    iframe.srcdoc=res.html;
    iframe.onload=resizeIframe;
  }
}

// ── SAVE ─────────────────────────────────────────────────
async function save(){
  const d=getData();
  const r=await fetch(PG_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',id:PAGE_ID,...d,slug:PG_CFG.slug})});
  const res=await r.json();
  if(res.ok){toast('Guardado ✓');}else{toast('Error: '+(res.error||'?'));}
}

function toast(msg){
  const t=g('toast');t.textContent=msg;
  t.style.transform='translateX(-50%) translateY(0)';
  setTimeout(()=>t.style.transform='translateX(-50%) translateY(60px)',2200);
}

document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='s'){e.preventDefault();save();}});

// Init preview with current page HTML
const initFrame = g('preview-iframe');
initFrame.srcdoc = JSON.parse(document.getElementById('pg-html').textContent);
initFrame.onload = resizeIframe;
// Rescale on window resize
window.addEventListener('resize', scaleIframe);
// Initial scale
setTimeout(scaleIframe, 100);

// ── Antes código en línea (CSP estricta, SEC-24) ──
function pgHide(el){ el.style.display='none'; }
function pgPrevent(e){ e.preventDefault(); }
function pgPick(id){ document.getElementById(id).click(); }
function pgColorPick(el){ document.getElementById('p-color-hex').value=el.value; liveUpdate(); }
function pgColorHex(el){ document.getElementById('p-color').value=el.value; liveUpdate(); }
function pgBgHex(el){ document.getElementById('p-bg-color').value=el.value; setBgColor(el.value); }
function pgTermDel(el){ el.closest('.term-card').remove(); liveUpdate(); }
// El logo pudo fallar antes de que cargara este archivo
document.querySelectorAll('img[data-on-error="pgHide"]').forEach(i => { if (i.complete && i.naturalWidth === 0) pgHide(i); });
