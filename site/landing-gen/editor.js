/* landing-gen/editor.js — Editor visual de landings (antes en línea en editor.php; CSP estricta, SEC-24 fase 2).
   Los datos de PHP llegan en #lge-data. La vista previa corre aislada en frame.php (frame-host.js): ya no es un iframe srcdoc. */
const LGE_BOOT = JSON.parse(document.getElementById('lge-data').textContent);
const LANDING_ID     = LGE_BOOT.id;
const KIND           = LGE_BOOT.kind;
const LANDING_NAME   = LGE_BOOT.name;
const IFRAME_SRC     = LGE_BOOT.src;
const INJECT_SCRIPT  = LGE_BOOT.inject;
const LANDING_PUB_URL= LGE_BOOT.pubUrl;

const ICONS = [
  {l:'flame',f:'fa-fire'},{l:'gift',f:'fa-gift'},{l:'zap',f:'fa-bolt'},
  {l:'star',f:'fa-star'},{l:'trophy',f:'fa-trophy'},{l:'percent',f:'fa-percent'},
  {l:'coins',f:'fa-coins'},{l:'shield-check',f:'fa-shield-halved'},
  {l:'target',f:'fa-bullseye'},{l:'trending-up',f:'fa-arrow-trend-up'},
  {l:'award',f:'fa-medal'},{l:'rocket',f:'fa-rocket'},
  {l:'diamond',f:'fa-gem'},{l:'crown',f:'fa-crown'},{l:'wallet',f:'fa-wallet'},
];

let iframeEl = null;
let currentDevice = 'desktop';
let sidebarBenefits = [];

/* ── DEVICE SHELLS ─────────────────────────── */
function getTime(){const n=new Date();return n.getHours()+':'+String(n.getMinutes()).padStart(2,'0');}
function screenH(){return Math.max(500, window.innerHeight - 52 - 46 - 60);}

function buildShell(dev){
  const canvas = document.getElementById('canvas');
  canvas.innerHTML = '';
  const w = {mobile:375,tablet:768,desktop:1280}[dev];
  const h = screenH();
  let shell = document.createElement('div');

  if(dev==='mobile'){
    shell.className='shell-mobile';
    shell.innerHTML=`
      <div class="ph-side v1"></div><div class="ph-side v2"></div>
      <div class="ph-side v3"></div><div class="ph-side pw"></div>
      <div class="ph-notch"></div>
      <div class="ph-status">
        <span class="ph-time" id="ph-clock">${getTime()}</span>
        <span class="ph-icons"><i class="fa-solid fa-signal" style="font-size:10px"></i> <i class="fa-solid fa-wifi" style="font-size:10px"></i> <i class="fa-solid fa-battery-three-quarters" style="font-size:11px"></i></span>
      </div>
      <div class="ph-screen" style="height:${h}px">
        <iframe id="preview-frame" sandbox="allow-scripts" class="preview-iframe" style="width:${w}px;height:${h}px"></iframe>
      </div>
      <div class="ph-home"></div>`;
  } else if(dev==='tablet'){
    shell.className='shell-tablet';
    shell.innerHTML=`
      <div class="tb-cam"></div>
      <div class="tb-screen" style="height:${h}px">
        <iframe id="preview-frame" sandbox="allow-scripts" class="preview-iframe" style="width:${w}px;height:${h}px"></iframe>
      </div>
      <div class="tb-home"></div>`;
  } else {
    shell.className='shell-desktop';
    shell.style.width=w+'px';
    shell.innerHTML=`
      <div class="db-bar">
        <div class="db-dots"><div class="db-dot r"></div><div class="db-dot y"></div><div class="db-dot g"></div></div>
        <div class="db-url">${escHtml(LGE_BOOT.host||location.host)} / ${escHtml(LANDING_NAME.toLowerCase().replace(/[^a-z0-9]+/g,'-'))}</div>
      </div>
      <div class="db-screen" style="height:${h}px">
        <iframe id="preview-frame" sandbox="allow-scripts" class="preview-iframe" style="width:${w}px;height:${h}px"></iframe>
      </div>`;
    // Scale to fit canvas
    requestAnimationFrame(()=>{
      const avail = canvas.clientWidth - 48;
      const scale = Math.min(1, avail / w);
      if(scale < 1){
        shell.style.transform=`scale(${scale})`;
        shell.style.transformOrigin='top center';
        shell.style.marginBottom=-( (h+38)*(1-scale) )+'px';
      }
    });
  }

  canvas.appendChild(shell);
  iframeEl = document.getElementById('preview-frame');
  lgFrameShow(iframeEl, liveSrc);   // aislado en frame.php (sin allow-same-origin): corren los scripts de la landing y del editor visual
}

/* El preview se arma con la última versión editada, no con la guardada: cambiar de dispositivo no pierde cambios */
let liveSrc = IFRAME_SRC;
function withInject(html){
  return html.includes('</body>') ? html.replace('</body>', INJECT_SCRIPT+'</body>') : html + INJECT_SCRIPT;
}
function setDevice(dev){
  if(dev===currentDevice) return;
  currentDevice = dev;
  document.querySelectorAll('.dev-btn').forEach(b=>b.classList.toggle('active', b.dataset.dev===dev));
  optSyncUI();
  if(!iframeEl){ buildShell(dev); return; }
  // Pide el HTML vivo del preview y recién ahí rearma el marco del dispositivo nuevo
  let done=false;
  const fin=html=>{
    if(done) return; done=true;
    removeEventListener('message',onHtml,true);
    if(html) liveSrc=withInject(html);
    buildShell(currentDevice);
  };
  const onHtml=e=>{ if(e.data&&e.data.t==='html'){ e.stopImmediatePropagation(); fin(e.data.html); } };
  addEventListener('message',onHtml,true);
  frame('getHTML');
  setTimeout(()=>fin(null),1500);
}

/* ── POSTMESSAGE ───────────────────────────── */
function frame(t,extra){if(!iframeEl)return;iframeEl.contentWindow.postMessage(Object.assign({t},extra),'*');}
function frameSet(sel,i,val,isHtml){frame('set',{sel,i,val,isHtml:!!isHtml});}
function frameCTA(obj){frame('setCTA',obj);}

/* ── INCOMING MESSAGES ─────────────────────── */

/* Mapea selectores (exactos o por patrón) a secciones del sidebar */
function selToSec(sel) {
  if (!sel) return null;
  const s = sel.toLowerCase();
  if (s==='.acc-title'||s==='li'||s==='strong') return 'sec-tyc';
  if (s.startsWith('.hero-t')||s==='.hero-sub') return 'sec-cta';
  if (s==='h1'||s.includes('cta')||s.includes('title')||s==='h2'||s==='h3') return 'sec-cta';
  if (s.includes('vigencia')||s.includes('date')||s.includes('summary-date')) return 'sec-vig';
  if (s.includes('narrative')||s.includes('narr')||s==='p') return 'sec-narr';
  if (s.includes('benefit')||s.includes('badge')) return 'sec-bens';
  if (s.includes('tyc')||s.includes('section-title')||s.includes('example')) return 'sec-tyc';
  if (s.includes('hero')||s.includes('image')) return 'sec-hero';
  return null;
}

function selToName(sel) {
  if (!sel) return 'elemento';
  const s = sel.toLowerCase();
  if (s==='h1') return 'Título principal';
  if (s==='.hero-t1') return 'Título (1ª línea)';
  if (s==='.hero-t2') return 'Título destacado';
  if (s==='.hero-sub') return 'Subtítulo';
  if (s==='.acc-title') return 'Título del desplegable';
  if (s.includes('cta')) return 'Botón CTA';
  if (s.includes('vigencia')||s.includes('summary-date')) return 'Fechas de vigencia';
  if (s.includes('promo-narrative')||s.includes('narrative')) return 'Narrativa';
  if (s.includes('benefit-value')) return 'Valor del beneficio';
  if (s.includes('benefit-label')) return 'Etiqueta del beneficio';
  if (s.includes('section-title')) return 'Título cláusula';
  if (s.includes('tyc')) return 'T&C';
  if (s.includes('example')) return 'Ejemplo numérico';
  return sel.replace(/\./g,'').replace(/-/g,' ');
}

let _focusT;
window.addEventListener('message', e=>{
  const d=e.data; if(!d||!d.t)return;

  if(d.t==='init'){
    // Cada plantilla tiene sus campos: los que no existen en el panel se saltean
    const put=(id,v)=>{const el=document.getElementById(id);if(el)el.value=v||'';};
    put('f-title',   d.title);
    put('f-cta-text',d.ctaText);
    put('f-cta-url', d.ctaUrl);
    put('f-vig',     d.vigencia);
    put('f-narr',    d.narrative);
    put('f-hero-img',KIND==='textos' ? d.arte : d.heroImg);
    put('f-bg-img',  d.fondo);
    put('f-t1',      d.t1);
    put('f-t2',      d.t2);
    put('f-sub',     d.sub);
    if(document.getElementById('benefits-list')) buildBenefits(d.benefits||[]);
    // Efectos: los murciélagos están prendidos con fx-murcielagos, el resto apagado con fx-no-<efecto>
    const cls=' '+(d.bodyClass||'')+' ';
    document.querySelectorAll('[data-fx]').forEach(cb=>{
      const k=cb.dataset.fx;
      cb.checked = k==='murcielagos' ? cls.includes(' fx-murcielagos ') : !cls.includes(' fx-no-'+k+' ');
    });
  }

  if(d.t==='initOpts'&&d.o){
    for(const g in OPT) Object.assign(OPT[g], d.o[g]||{});
    optSyncUI();
  }

  if(d.t==='focus'){
    const name = selToName(d.sel);
    document.getElementById('focus-txt').textContent = 'Editando: ' + name;
    const fb = document.getElementById('focus-bar');
    fb.classList.add('show');
    clearTimeout(_focusT); _focusT = setTimeout(()=>fb.classList.remove('show'), 3500);
    const secId = selToSec(d.sel);
    if(secId){
      document.querySelectorAll('details.sec').forEach(s=>s.classList.remove('hl'));
      const sec = document.getElementById(secId);
      if(sec){ sec.setAttribute('open',''); sec.classList.add('hl'); }
    }
  }

  if(d.t==='change'){
    const s = (d.sel||'').toLowerCase();
    const put=(id,v)=>{const el=document.getElementById(id);if(el&&document.activeElement!==el)el.value=v;};
    if(s==='.hero-t1'){ put('f-t1', d.raw??d.text); return; }
    if(s==='.hero-t2'){ put('f-t2', d.raw??d.text); return; }
    if(s==='.hero-sub'){ put('f-sub', d.html||d.text); return; }
    if(s==='.acc-title') return;
    if(s==='h1'&&document.getElementById('f-title')) document.getElementById('f-title').value = d.text;
    if(s.includes('cta'))                  document.getElementById('f-cta-text').value = d.text;
    if(s.includes('summary-date')||s.includes('vigencia')) document.getElementById('f-vig').value = d.text;
    if(s.includes('narrative')||s.includes('narr'))        document.getElementById('f-narr').value = d.html||d.text;
    if(s.includes('benefit-value')){
      const inp=document.querySelector(`.benefit-card[data-idx="${d.i}"] .bv`);
      if(inp&&document.activeElement!==inp)inp.value=d.text;
    }
    if(s.includes('benefit-label')){
      const inp=document.querySelector(`.benefit-card[data-idx="${d.i}"] .bl`);
      if(inp&&document.activeElement!==inp)inp.value=d.text;
    }
  }

  if(d.t==='html'){
    pendingHTML=d.html;
    if(pendingAction==='_fillCodeEditor'){
      document.getElementById('code-textarea').value=d.html||'';
      pendingAction=null; pendingHTML=null; return;
    }
    if(pendingAction==='save')     doSave(pendingHTML);
    if(pendingAction==='publish')  doPublish(pendingHTML);
    if(pendingAction==='download') doDownload(pendingHTML);
    if(pendingAction==='copy')     doCopy(pendingHTML);
    if(pendingAction==='preview')  doPreview(pendingHTML);
    pendingAction=null; pendingHTML=null;
  }
});

/* ── BENEFITS ──────────────────────────────── */
function buildBenefits(benefits){
  sidebarBenefits = benefits;
  const list=document.getElementById('benefits-list');
  const cards = benefits.map((b,i)=>`
    <div class="benefit-card" data-idx="${i}">
      <div class="b-num">Beneficio ${i+1}
        <button class="b-del" data-on-click="removeBenefit" data-click-1="j:${i}" title="Eliminar"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="icon-grid">${ICONS.map(ic=>`
        <button class="ico${ic.l===b.icon?' on':''}" title="${ic.l}" data-on-click="pickIcon" data-click-1="j:${i}" data-click-2="s:${ic.l}" data-click-3="this">
          <i class="fa-solid ${ic.f}"></i>
        </button>`).join('')}
      </div>
      <div class="row">
        <div class="field"><label>Valor</label>
          <input type="text" class="bv" value="${escHtml(b.value)}" data-on-input="frameSet" data-input-1="s:.benefit-value" data-input-2="j:${i}" data-input-3="value">
        </div>
        <div class="field"><label>Etiqueta</label>
          <input type="text" class="bl" value="${escHtml(b.label)}" data-on-input="frameSet" data-input-1="s:.benefit-label" data-input-2="j:${i}" data-input-3="value">
        </div>
      </div>
    </div>`).join('');
  const addBtn = `<button class="b-add" data-on-click="addBenefit"><i class="fa-solid fa-plus"></i> Agregar beneficio</button>`;
  list.innerHTML = (benefits.length ? cards : '<div class="empty-b"><i class="fa-solid fa-tag"></i><p>Sin beneficios — agrega uno</p></div>') + addBtn;
}

function addBenefit(){
  const newB = {icon:'flame', value:'', label:''};
  sidebarBenefits.push(newB);
  buildBenefits(sidebarBenefits);
  frame('addBenefit',{});
}

function removeBenefit(idx){
  sidebarBenefits.splice(idx,1);
  buildBenefits(sidebarBenefits);
  frame('removeBenefit',{i:idx});
}
function pickIcon(idx,icon,btn){
  btn.closest('.icon-grid').querySelectorAll('.ico').forEach(b=>b.classList.remove('on'));
  btn.classList.add('on');
  frame('setIcon',{i:idx,icon});
}

/* ── HERO UPLOAD ───────────────────────────── */
// target: 'hero' (imagen principal / arte) o 'bg' (fondo de la página, plantilla de textos)
function applyImage(url, target){
  if(target==='bg') applyBg(url);
  else if(target==='tit') optSet('tit',{url,on:true});
  else if(target==='ban') optSet('ban',{url,on:true});
  else if(target==='banm') optSet('ban',{urlM:url,on:true});
  else applyHero(url);
}

/* ── OPCIONES DE LA PLANTILLA DE TEXTOS: formato del fondo, imagen en vez del título, arte, hero arriba ── */
const OPT={bg:{fit:'cover',pos:'center top',zoom:100,dim:0,blur:0},tit:{on:false,url:'',w:{d:null,t:null,m:null},sub:false},arte:{on:true,w:{d:null,t:null,m:null}},ban:{on:false,url:'',urlM:'',full:false,link:true}};

/* Tamaños por dispositivo, como en Elementor: d (compu), t (tablet), m (celular). null = hereda.
   El título hereda celular ← tablet ← compu; el arte en celular arranca en 320 px (lo que trae la plantilla). */
const DEVK={desktop:'d',tablet:'t',mobile:'m'}, DEV_IC={desktop:'fa-desktop',tablet:'fa-tablet-screen-button',mobile:'fa-mobile-screen'};
const RSP={tit:{id:'o-tit-w',u:'%',def:{d:100}},arte:{id:'o-arte-w',u:' px',def:{d:480,m:320}}};
function rspValue(grp,k){
  const w=OPT[grp].w, def=RSP[grp].def;
  const chain={d:['d'],t:['t','d'],m:grp==='arte'?['m']:['m','t','d']}[k];
  for(const c of chain) if(w[c]!=null) return {v:w[c],own:c===k};
  return {v:def[k]??def.d,own:false};
}
function optRsp(grp,v){
  OPT[grp].w[DEVK[currentDevice]] = v;
  frame(OPT_MSG[grp], OPT[grp]);
  optSyncUI();
}
const OPT_MSG={bg:'setBgFmt',tit:'setTitulo',arte:'setArte',ban:'setBanner'};
// optSet('bg','dim',40) o optSet('ban',{url,on:true})
function optSet(grp,k,v){
  Object.assign(OPT[grp], typeof k==='object' ? k : {[k]:v});
  frame(OPT_MSG[grp], OPT[grp]);
  optSyncUI();
}
function optResetBg(){ optSet('bg',{fit:'cover',pos:'center top',zoom:100,dim:0,blur:0}); }
function optSyncUI(){
  if(KIND!=='textos') return;
  const $=id=>document.getElementById(id);
  const val=(id,v)=>{const el=$(id);if(el&&document.activeElement!==el)el.value=v;};
  const rng=(id,v,u)=>{const el=$(id);if(!el)return;el.value=v;el.nextElementSibling.textContent=v+u;};
  const seg=(id,on)=>document.querySelectorAll('#'+id+' button').forEach(b=>b.classList.toggle('on',b.dataset.v===(on?'1':'0')));
  const {bg,tit,arte,ban}=OPT;
  val('o-bg-fit',bg.fit);
  document.querySelectorAll('#o-bg-pos button').forEach(b=>b.classList.toggle('on',b.dataset.pos===bg.pos));
  rng('o-bg-zoom',bg.zoom,'%'); rng('o-bg-dim',bg.dim,'%'); rng('o-bg-blur',bg.blur,' px');
  seg('o-tit-mode',tit.on);
  $('o-tit-blk').hidden=!tit.on; $('o-tit-texto').hidden=tit.on; $('o-cta-textf').hidden=tit.on;
  $('o-tit-subf').hidden=tit.on&&!tit.sub;
  val('o-tit-url',tit.url); $('o-tit-sub').checked=tit.sub;
  // Controles por dispositivo: muestran el valor del dispositivo del preview; gris = heredado
  const k=DEVK[currentDevice];
  for(const g in RSP){
    const {v,own}=rspValue(g,k), el=$(RSP[g].id); if(!el) continue;
    el.value=v; el.nextElementSibling.textContent=v+RSP[g].u;
    el.closest('.rng').classList.toggle('inh',!own);
    el.closest('.rng').querySelector('.rsp-x').hidden=!own;
  }
  document.querySelectorAll('.devs').forEach(d=>{
    d.innerHTML=Object.keys(DEVK).map(dv=>`<button type="button" class="${dv===currentDevice?'on':''}" title="${{desktop:'Compu',tablet:'Tablet',mobile:'Celular'}[dv]}" data-on-click="setDevice" data-click-1="s:${dv}"><i class="fa-solid ${DEV_IC[dv]}"></i></button>`).join('');
  });
  $('o-arte-on').checked=arte.on; $('o-arte-blk').hidden=!arte.on; $('o-arte-off').hidden=arte.on;
  $('o-ban-on').checked=ban.on; $('o-ban-blk').hidden=!ban.on;
  val('o-ban-url',ban.url); val('o-ban-urlm',ban.urlM); seg('o-ban-full',ban.full); $('o-ban-link').checked=ban.link;
}
function applyBg(url){
  const el=document.getElementById('f-bg-img'); if(el) el.value=url;
  frame('setBg',{url});
}
function uploadHero(input, target){
  const file=input.files[0];if(!file)return;
  if(file.size>8*1024*1024){toast('Imagen muy grande (máx 8MB)','err');return;}
  const r=new FileReader();
  r.onload=async ev=>{
    const dataUrl=ev.target.result;
    toast('Subiendo imagen...','');
    try{
      const res=await fetch('?id='+LANDING_ID,{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'upload_hero',dataUrl})});
      const d=await res.json();
      if(d.ok&&d.url){
        applyImage(d.url, target);
        toast('Imagen guardada ✓','ok');
      } else {
        // fallback to base64 if server save fails
        applyImage(dataUrl, target);
        toast('Imagen cargada (local)','ok');
      }
    }catch(e){ applyImage(dataUrl, target); toast('Imagen cargada (local)','ok'); }
    if(input)input.value='';
  };
  r.readAsDataURL(file);
}

function applyHero(url){
  document.getElementById('f-hero-img').value=url;
  frame('setHero',{url});
}

/* ── HERO GALLERY ──────────────────────────── */
let galleryTarget='hero';
function openGallery(target){
  galleryTarget=target||'hero';
  document.getElementById('hero-modal').classList.add('open');
  loadGallery();
}
function closeGallery(){
  document.getElementById('hero-modal').classList.remove('open');
}
async function loadGallery(){
  const wrap=document.getElementById('hm-grid-wrap');
  wrap.className='hm-loading'; wrap.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Cargando...';
  try{
    const res=await fetch('?id='+LANDING_ID,{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'list_heroes'})});
    const d=await res.json();
    const imgs=d.images||[];
    if(!imgs.length){
      wrap.className='hm-empty';
      wrap.innerHTML='<i class="fa-solid fa-images"></i><p>Todavía no hay imágenes guardadas.<br>Sube la primera usando el botón de arriba.</p>';
      return;
    }
    wrap.className='hm-grid';
    wrap.innerHTML=imgs.map(img=>`
      <div class="hm-img-wrap" data-on-click="selectGalleryImage" data-click-1="s:${escHtml(img.url)}">
        <img src="${escHtml(img.url)}" alt="" loading="lazy">
        <div class="hm-img-ov"><span>Usar</span></div>
      </div>`).join('');
  }catch(e){
    wrap.className='hm-empty'; wrap.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i><p>Error al cargar la galería</p>';
  }
}
function selectGalleryImage(url){
  applyImage(url, galleryTarget);
  closeGallery();
  toast('Imagen aplicada ✓','ok');
}
async function galleryUpload(input){
  const file=input.files[0];if(!file)return;
  if(file.size>8*1024*1024){toast('Imagen muy grande (máx 8MB)','err');return;}
  const r=new FileReader();
  r.onload=async ev=>{
    const wrap=document.getElementById('hm-grid-wrap');
    const prev=wrap.innerHTML; wrap.className='hm-loading'; wrap.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...';
    try{
      const res=await fetch('?id='+LANDING_ID,{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'upload_hero',dataUrl:ev.target.result})});
      const d=await res.json();
      if(d.ok&&d.url){ applyImage(d.url, galleryTarget); closeGallery(); toast('Imagen subida y aplicada ✓','ok'); }
      else { wrap.className=''; wrap.innerHTML=prev; toast('Error al subir','err'); }
    }catch(e){ wrap.className=''; wrap.innerHTML=prev; toast('Error de red','err'); }
    input.value='';
  };
  r.readAsDataURL(file);
}

/* ── SAVE / DOWNLOAD / COPY ────────────────── */
let pendingAction=null,pendingHTML=null;

function getHTML(action){
  if(codeEditorOpen){
    const html=document.getElementById('code-textarea').value.trim();
    if(!html){toast('Sin contenido','err');return;}
    if(action==='save')     { doSave(html);     return; }
    if(action==='publish')  { doPublish(html);  return; }
    if(action==='download') { doDownload(html); return; }
    if(action==='copy')     { doCopy(html);     return; }
    if(action==='preview')  { doPreview(html);  return; }
    return;
  }
  pendingAction=action;
  if(!iframeEl){toast('Preview no listo','err');return;}
  iframeEl.contentWindow.postMessage({t:'getHTML'},'*');
  setTimeout(()=>{if(pendingAction){toast('Timeout','err');pendingAction=null;}},5000);
}

async function saveLanding(){
  const btn=document.getElementById('save-btn');
  btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Guardando…';
  getHTML('save');
}
/** Respuesta del servidor como JSON; si no lo es, un error que dice qué pasó (antes todo era "Error de red"). */
async function lgJson(r){
  const t=await r.text();
  try{ return JSON.parse(t); }catch(e){}
  if(r.redirected||/login\.php/.test(r.url)||/<form[^>]+login/i.test(t)) return {error:'La sesión venció: abre Toolz en otra pestaña, entra y vuelve a tocar Guardar (no cierres esta).'};
  if(r.status===403||r.status===406) return {error:'El servidor rechazó el guardado ('+r.status+'), posiblemente por el contenido. Avisa a soporte con el nombre de la landing.'};
  if(r.status===413) return {error:'La landing pesa demasiado para guardarse ('+r.status+').'};
  return {error:'El servidor respondió con un error ('+r.status+'): no se guardó.'};
}
async function doSave(html){
  const heroImg=document.getElementById('f-hero-img')?.value||'';
  try{
    const r=await fetch('?id='+LANDING_ID,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',html,heroImg})});
    const d=await lgJson(r);
    toast(d.ok?'Guardada ✓':(d.error||'Error'),d.ok?'ok':'err');
  }catch(e){toast('No hay conexión con el servidor: no se guardó','err');}
  const btn=document.getElementById('save-btn');
  btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Guardar';
}
/* ── PUBLISH ───────────────────────────────── */
function publishLanding(){
  const btn=document.getElementById('pub-btn');
  btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Publicando…';
  getHTML('publish');
}
async function doPublish(html){
  const heroImg=document.getElementById('f-hero-img')?.value||'';
  try{
    const r=await fetch('?id='+LANDING_ID,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'publish',html,heroImg})});
    const d=await lgJson(r);
    if(d.ok&&d.url){
      showPubBanner(d.url);
      toast('Landing publicada ✓','ok');
    } else {
      toast(d.error||'Error al publicar','err');
    }
  }catch(e){toast('Error de red','err');}
  const btn=document.getElementById('pub-btn');
  btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rocket"></i> Publicar';
}
function showPubBanner(url){
  const banner=document.getElementById('pub-banner');
  const link=document.getElementById('pub-url-link');
  const openBtn=document.getElementById('pub-open-btn');
  link.textContent=url; link.href=url;
  openBtn.href=url;
  banner.classList.add('show');
  banner.scrollIntoView({behavior:'smooth',block:'nearest'});
}
function copyPubUrl(){
  const url=document.getElementById('pub-url-link').href;
  navigator.clipboard.writeText(url).then(()=>toast('URL copiada ✓','ok')).catch(()=>toast('Error al copiar','err'));
}

function previewLanding(){getHTML('preview');}
function doPreview(html){
  // En frame.php (aislada): un blob: heredaría la CSP estricta de esta página y no correrían los scripts de la landing
  const w=lgFrameOpen(html);
  if(!w)toast('Habilita los popups para ver la preview','err');
}
function downloadHTML(){getHTML('download');}
function doDownload(html){
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([html],{type:'text/html'}));
  a.download=LANDING_NAME.replace(/[^a-z0-9]/gi,'-')+'.html';
  a.click(); toast('Descargando…','ok');
}
function copyHTML(){getHTML('copy');}
function doCopy(html){
  navigator.clipboard.writeText(html).then(()=>toast('HTML copiado ✓','ok')).catch(()=>toast('Error al copiar','err'));
}

/* ── HELPERS ───────────────────────────────── */
function escHtml(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
let _toastT;
function toast(msg,type){
  const el=document.getElementById('toast');
  el.textContent=msg; el.className='show '+(type||'');
  clearTimeout(_toastT); _toastT=setTimeout(()=>el.className='',3000);
}

/* ── EDITOR DE CÓDIGO ────────────────────── */
let codeEditorOpen = false;

function toggleCodeEditor(){
  codeEditorOpen = !codeEditorOpen;
  const canvas = document.getElementById('canvas');
  const panel  = document.getElementById('code-panel');
  const btn    = document.getElementById('code-btn');
  btn.classList.toggle('active', codeEditorOpen);

  if(codeEditorOpen){
    canvas.style.display = 'none';
    panel.style.display  = 'flex';
    // Pedir HTML limpio al iframe (sin scripts de editor)
    if(iframeEl){
      pendingAction = '_fillCodeEditor';
      iframeEl.contentWindow.postMessage({t:'getHTML'},'*');
      setTimeout(()=>{ if(pendingAction==='_fillCodeEditor'){ pendingAction=null; } },3000);
    }
  } else {
    // Al cerrar el editor de código, aplicar los cambios al iframe
    const html = document.getElementById('code-textarea').value.trim();
    if(html) _injectAndLoad(html);
    canvas.style.display = '';
    panel.style.display  = 'none';
  }
}

function _injectAndLoad(html){
  if(!iframeEl) return;
  // Re-inyectar scripts del editor visual
  liveSrc = withInject(html);
  lgFrameShow(iframeEl, liveSrc);
}

function applyCodeChanges(){
  const html = document.getElementById('code-textarea').value.trim();
  if(!html){ toast('Sin contenido','err'); return; }
  _injectAndLoad(html);
  toast('Código aplicado al preview','ok');
}

// Tab en el textarea
document.getElementById('code-textarea').addEventListener('keydown', function(e){
  if(e.key==='Tab'){
    e.preventDefault();
    const s=this.selectionStart, end=this.selectionEnd;
    this.value = this.value.substring(0,s)+'  '+this.value.substring(end);
    this.selectionStart = this.selectionEnd = s+2;
  }
});

/* ── INIT ──────────────────────────────────── */
buildShell('desktop');
setInterval(()=>{const el=document.getElementById('ph-clock');if(el)el.textContent=getTime();},10000);

/* ── INSERTAR EN CMS ─────────────────────── */
function buildCMSCode(url){
  return `<html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>.iframe-wrap{position:relative;width:100%;height:80vh;overflow:hidden}.iframe-wrap iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:none}@media (min-width:768px){.iframe-wrap{height:0;padding-bottom:56.25%}}</style></head><body>\n<div class="iframe-wrap"><iframe src="${url}" allowfullscreen="allowfullscreen" loading="lazy"></iframe></div>\n</body></html>`;
}

function updateCMSPreview(){
  const url = document.getElementById('cms-url-input').value.trim();
  document.getElementById('cms-code-output').value = url ? buildCMSCode(url) : '';
}

function openCMSModal(){
  const modal = document.getElementById('cms-modal');
  modal.style.display = 'flex';
  const inp = document.getElementById('cms-url-input');
  inp.value = LANDING_PUB_URL || '';
  document.getElementById('cms-no-url-hint').style.display = LANDING_PUB_URL ? 'none' : 'block';
  updateCMSPreview();
  if(!LANDING_PUB_URL) inp.focus();
}

function closeCMSModal(){
  document.getElementById('cms-modal').style.display = 'none';
}

function copyCMSCode(){
  const code = document.getElementById('cms-code-output').value;
  if(!code){ toast('Ingresa una URL primero','err'); return; }
  navigator.clipboard.writeText(code)
    .then(()=>toast('Código CMS copiado ✓','ok'))
    .catch(()=>toast('Error al copiar','err'));
}

document.getElementById('cms-modal').addEventListener('click', function(e){
  if(e.target===this) closeCMSModal();
});

/* ── LOCK DE EDICIÓN ──────────────────────── */
function edLockPost(action){fetch('editor.php?id='+LANDING_ID,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action})}).catch(()=>{});}
setInterval(()=>edLockPost('lock_heartbeat'), 30000);
window.addEventListener('beforeunload', ()=>edLockPost('lock_release'));
document.addEventListener('visibilitychange', ()=>{ if(document.visibilityState==='hidden') edLockPost('lock_release'); });

// ── Eventos que antes estaban en línea (assets/g7-on.js los llama por nombre) ──
function lgFrameUrl(t, url){ frame(t, {url}); }   // campo URL de la imagen del hero o del fondo
function lgHidePubBanner(){ document.getElementById('pub-banner').classList.remove('show'); }
function lgOptPos(el){ optSet('bg', 'pos', el.dataset.pos); }
function lgCtaText(v){ frameCTA({text: v}); }
function lgCtaHref(v){ frameCTA({href: v}); }
function lgSetFx(el){ frame('setFx', {k: el.dataset.fx, on: el.checked}); }
