/* mailingmaker/mailings.js — Generador de mailings (antes en línea en generador_mailings.html; CSP estricta, SEC-24 fase 2). */
// ════════════════════════════════════════════════════════════
// CONFIG POR PLATAFORMA
// Cada plataforma define su estructura exacta de template. Los datos de las marcas del sitio vienen del
// paquete de marcas (marcas_js.php → window.MM_PACK.mailings, ver mm_pack.js); sin paquete se usa este ejemplo neutro.
// Campos de una plataforma: name, short, country, h1Color, ctaColor, ctaTextColor,
//   hasHeaderBlack (franja negra con logo), hasHeroBg + heroBgUrl (portada con H1 encima), hasMetodosPago + paymentUrl
//   (+ paymentNoBg), hasClub + supportUrl/clubBtnUrl/clubLabel/clubBtnLbl (+ clubIsSimple), hasFooterUrl + footerUrlText,
//   hasDisclaimer + disclaimerText, bodyAlign, ctaExtraCss, logoUrl/logoWidth/logoMargin/logoLinkUtm (sin logoUrl va el nombre),
//   spacer (gif espaciador, opcional), iconsBase + socialSet + socialNames (íconos de redes), links {main, club, pago, ig, fb, x, wa, tt, yt, tg}
// ════════════════════════════════════════════════════════════
const MM_EXAMPLE = {
  defaultPlat: 'ejemplo',
  cards: [ { id:'ejemplo', label:'Mi marca', tag:'Marca de ejemplo' } ],
  lgDomains: { ejemplo:'example.com' },
  sample: {
    body1: 'Llega el Superclásico con alta intensidad 💥 y en <b>Mi marca</b> te damos un motivo más para disfrutar de cada juego a través de 1 Freebet de <b>hasta $60.000</b> para elevar la temperatura a tus apuestas ⚽🔥.',
  },
  urlPlaceholder: 'https://example.com/promo',
  tplPlaceholder: 'Ej: Freebet Clásico · Mi marca',
  platforms: {
    ejemplo: {
      name:'Mi marca', short:'MI MARCA', country:'Marca de ejemplo',
      h1Color:'#FF4800', ctaColor:'#27CC2A', ctaTextColor:'#FFFFFF',
      hasHeaderBlack: true,
      hasMetodosPago: false,
      hasClub: false,
      hasFooterUrl: true,
      hasDisclaimer: true,
      hasSocial: false,
      logoUrl:'',                 // sin logo: el encabezado muestra el nombre
      logoWidth:146, logoMargin:'1.5em 0',
      logoLinkUtm:'',
      paymentUrl:'', supportUrl:'', clubBtnUrl:'', clubLabel:'', clubBtnLbl:'',
      spacer:'', iconsBase:'',
      footerUrl:'https://example.com', footerUrlText:'www.example.com',
      disclaimerText:'Mi marca · Aplican T&C',
      links:{ main:'https://example.com', club:'', pago:'', ig:'', fb:'', x:'', wa:'', tt:'', yt:'', tg:'' },
      socialSet:[],
      socialNames:{},
    },
  },
};
const MMP = mmPack('mailings', MM_EXAMPLE);
const PC = MMP.platforms;

// Estado global
let curPlat = MMP.defaultPlat;
let imgUrl  = '';
let galItems = [], galSelected = null, pendingFile = null;

// ════════════════════════════════════════════════════════════
// WYSIWYG — helpers de selección
// ════════════════════════════════════════════════════════════
// ════════════════════════════════════════════════════════════
// WYSIWYG
// ════════════════════════════════════════════════════════════
function buildTB(tbId, edId) {
  const tb = document.getElementById(tbId);
  const ed = document.getElementById(edId);
  let activeColor = '#111111'; // color activo del picker

  try { document.execCommand('defaultParagraphSeparator', false, 'br'); } catch(e){}

  // ── PASTE: extraer texto puro, ignorar todo formato externo ──
  ed.addEventListener('paste', function(e){
    e.preventDefault();
    // Tomar siempre texto plano para evitar cualquier estilo heredado
    const text = e.clipboardData.getData('text/plain');
    if(!text) return;
    // Convertir saltos de línea reales en <br>
    const lines = text.split(/\r?\n/);
    let html = '';
    lines.forEach((line, i) => {
      // Escapar HTML del texto
      const safe = line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      html += safe;
      if(i < lines.length - 1) html += '<br>';
    });
    document.execCommand('insertHTML', false, html);
    update();
  });

  // ── ENTER → <br> ────────────────────────────────────────
  ed.addEventListener('keydown', function(e){
    if(e.key === 'Enter' && !e.shiftKey){
      e.preventDefault();
      document.execCommand('insertLineBreak');
      update();
    }
  });

  // ── TOOLBAR ─────────────────────────────────────────────
  const defs = [
    {cmd:'bold',         html:'<i class="fa-solid fa-bold"></i>',        title:'Negrita'},
    {cmd:'italic',       html:'<i class="fa-solid fa-italic"></i>',      title:'Cursiva'},
    {cmd:'underline',    html:'<i class="fa-solid fa-underline"></i>',   title:'Subrayado'},
    {sep:true},
    {cmd:'insertUnorderedList', html:'<i class="fa-solid fa-list-ul"></i>', title:'Lista'},
    {sep:true},
    {cmd:'justifyLeft',  html:'<i class="fa-solid fa-align-left"></i>',   title:'Izquierda'},
    {cmd:'justifyCenter',html:'<i class="fa-solid fa-align-center"></i>', title:'Centro'},
    {cmd:'justifyRight', html:'<i class="fa-solid fa-align-right"></i>',  title:'Derecha'},
    {sep:true},
    {sp:'color',         html:'<span style="display:flex;flex-direction:column;align-items:center;gap:2px;line-height:1;"><b style="font-size:12px;">A</b><span class="cbar_'+edId+'" style="width:14px;height:3px;background:#111;border-radius:1px;display:block;"></span></span>', title:'Color de texto'},
    {sep:true},
    {sp:'link',          html:'<i class="fa-solid fa-link"></i>',        title:'Enlace'},
    {sp:'unlink',        html:'<i class="fa-solid fa-link-slash"></i>',  title:'Quitar enlace'},
    {sep:true},
    {sp:'clear',         html:'<i class="fa-solid fa-eraser"></i>',      title:'Limpiar formato'},
  ];

  // ── PALETA DE COLORES (panel propio, no input nativo) ───
  const PALETTE = [
    {hex:'#111111', name:'Negro'},
    {hex:'#444444', name:'Gris'},
    {hex:'#FF4800', name:'Naranja'},
    {hex:'#FF0000', name:'Rojo'},
    {hex:'#27CC2A', name:'Verde'},
    {hex:'#FFD400', name:'Amarillo'},
    {hex:'#0066FF', name:'Azul'},
    {hex:'#9333EA', name:'Violeta'},
    {hex:'#FFFFFF', name:'Blanco'}
  ];

  // Crear panel desplegable de colores (oculto por defecto)
  const palette = document.createElement('div');
  palette.className = 'cpal cpal_'+edId;
  palette.style.cssText = 'display:none;position:absolute;background:#1a1a1a;border:1px solid rgba(255,102,0,0.25);border-radius:6px;padding:6px;gap:4px;grid-template-columns:repeat(5,1fr);z-index:100;box-shadow:0 4px 12px rgba(0,0,0,0.5);';
  PALETTE.forEach(c => {
    const sw = document.createElement('button');
    sw.type = 'button';
    sw.title = c.name;
    sw.style.cssText = 'width:22px;height:22px;border-radius:4px;border:1px solid rgba(255,255,255,0.15);background:'+c.hex+';cursor:pointer;padding:0;';
    // Importante: usar mousedown con preventDefault para no perder selección
    sw.addEventListener('mousedown', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      applyColorToSelection(c.hex);
      palette.style.display = 'none';
    });
    palette.appendChild(sw);
  });
  // Click fuera cierra la paleta
  document.addEventListener('mousedown', function(ev){
    if(!palette.contains(ev.target) && !ev.target.closest('.cbtn_'+edId)){
      palette.style.display = 'none';
    }
  });

  // Aplica color al rango actual del editor
  function applyColorToSelection(hex){
    const sel = window.getSelection();
    if(!sel || sel.rangeCount === 0) return;
    const range = sel.getRangeAt(0);
    if(!ed.contains(range.commonAncestorContainer)) return;
    if(range.collapsed) return; // sin selección, no hacer nada

    const isBlack = (hex === '#111111' || hex === '#000000');

    if(isBlack){
      // Negro → quitar todos los .wy-color dentro de la selección
      const frag = range.extractContents();
      const cleanFrag = (node) => {
        const spans = node.querySelectorAll ? node.querySelectorAll('span.wy-color') : [];
        spans.forEach(s => {
          while(s.firstChild) s.parentNode.insertBefore(s.firstChild, s);
          s.remove();
        });
      };
      cleanFrag(frag);
      range.insertNode(frag);
    } else {
      // Color → envolver en span.wy-color con color inline
      const span = document.createElement('span');
      span.className = 'wy-color';
      span.style.color = hex;
      try{
        range.surroundContents(span);
      } catch(e){
        const frag = range.extractContents();
        span.appendChild(frag);
        range.insertNode(span);
      }
      // Mover cursor al final
      const newRange = document.createRange();
      newRange.setStartAfter(span);
      newRange.collapse(true);
      sel.removeAllRanges();
      sel.addRange(newRange);
    }
    // Actualizar barra visual del botón
    document.querySelectorAll('.cbar_'+edId).forEach(b => b.style.background = hex);
    update();
  }

  defs.forEach(d => {
    if(d.sep){
      const s = document.createElement('div');
      s.className = 'wt-sep';
      tb.appendChild(s);
      return;
    }
    const b = document.createElement('button');
    b.className = 'wt-b';
    if(d.sp === 'color') b.classList.add('cbtn_'+edId);
    b.innerHTML = d.html;
    b.title     = d.title || '';
    b.type      = 'button';

    b.addEventListener('mousedown', ev => {
      ev.preventDefault(); // mantiene foco en el editor

      if(d.sp === 'color'){
        // Mostrar/ocultar paleta debajo del botón
        if(palette.style.display === 'grid'){
          palette.style.display = 'none';
        } else {
          const rect = b.getBoundingClientRect();
          palette.style.display = 'grid';
          palette.style.top  = (window.scrollY + rect.bottom + 4) + 'px';
          palette.style.left = (window.scrollX + rect.left) + 'px';
        }
        return;
      }

      if(d.cmd)  { document.execCommand(d.cmd, false, null); }
      else if(d.sp === 'link')   { const u=prompt('URL:','https://'); if(u) document.execCommand('createLink',false,u); }
      else if(d.sp === 'unlink') { document.execCommand('unlink',false,null); }
      else if(d.sp === 'clear')  { document.execCommand('removeFormat',false,null); }

      ed.focus();
      update();
    });

    tb.appendChild(b);
  });

  document.body.appendChild(palette);
}

// ════════════════════════════════════════════════════════════
// INIT
// ════════════════════════════════════════════════════════════
// ════════════════════════════════════════════════════════════
// SECCIONES EXTRAS — thumb + título + texto + CTA
// ════════════════════════════════════════════════════════════
let extraSecs = [];          // array de {id, title, text, ctaText, ctaUrl, imgUrl, imgExp}
let _xsecImgTarget = null;   // id de sección extra cuyo modal de imagen está abierto

function addExtraSec(kind){
  kind = kind || 'std';
  const id = 'xs_' + Date.now() + '_' + Math.floor(Math.random()*1000);
  if(kind === 'table'){
    extraSecs.push({
      id, kind:'table',
      header: '💰 Pozo total $6.500.000 distribuido entre los 20 mejores.',
      rows: [
        {pos:'01',      prize:'<b>$2.000.000</b>'},
        {pos:'02',      prize:'<b>$1.000.000</b>'},
        {pos:'03',      prize:'<b>$700.000</b>'},
        {pos:'04 - 05', prize:'<b>$400.000</b> c/u'},
        {pos:'06 - 10', prize:'<b>$150.000</b> c/u'},
        {pos:'11 - 15', prize:'<b>$70.000</b> c/u'},
        {pos:'16 - 20', prize:'<b>$45.000</b> c/u'},
      ],
    });
  } else {
    extraSecs.push({
      id, kind:'std',
      title: 'Nueva sección',
      text: 'Texto descriptivo de la sección.',
      ctaText: 'Ver Más',
      ctaUrl: '',
      imgUrl: '',
      imgExp: '',
    });
  }
  renderExtraSecs();
  update();
}

function removeExtraSec(id){
  if(!confirm('¿Eliminar esta sección?')) return;
  extraSecs = extraSecs.filter(s => s.id !== id);
  renderExtraSecs();
  update();
}

function updateExtraSec(id, field, val){
  const s = extraSecs.find(x => x.id === id);
  if(!s) return;
  s[field] = val;
  update();
}

// ── Helpers para filas de tabla de premios ──
function addXsecRow(id){
  const s = extraSecs.find(x => x.id === id);
  if(!s || s.kind !== 'table') return;
  s.rows.push({pos:'', prize:''});
  renderExtraSecs();
  update();
}
function removeXsecRow(id, idx){
  const s = extraSecs.find(x => x.id === id);
  if(!s || s.kind !== 'table') return;
  s.rows.splice(idx, 1);
  renderExtraSecs();
  update();
}
function updateXsecRow(id, idx, field, val){
  const s = extraSecs.find(x => x.id === id);
  if(!s || s.kind !== 'table') return;
  if(!s.rows[idx]) return;
  s.rows[idx][field] = val;
  update();
}

function setExtraSecImg(id, absUrl, serverUrl){
  const s = extraSecs.find(x => x.id === id);
  if(!s) return;
  s.imgUrl  = absUrl;
  s.imgExp  = buildExportImgUrl(serverUrl || absUrl);
  renderExtraSecs();
  update();
}

function openExtraSecImgModal(id){
  _xsecImgTarget = id;
  openImgModal();
}

function renderExtraSecs(){
  const container = document.getElementById('extraSecsList');
  if(!container) return;
  if(!extraSecs.length){ container.innerHTML = ''; return; }
  container.innerHTML = extraSecs.map((s, idx) => {
    if(s.kind === 'table'){
      const rowsHtml = s.rows.map((r, ri) => `
        <div style="display:flex;gap:5px;align-items:center;margin-bottom:4px;">
          <input type="text" value="${eh(r.pos)}" placeholder="01"
            data-on-input="updateXsecRow" data-input-1="s:${s.id}" data-input-2="j:${ri}" data-input-3="s:pos" data-input-4="value"
            style="width:80px;padding:5px 7px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.09);border-radius:5px;color:var(--text);font-size:11px;font-family:inherit;">
          <input type="text" value="${eh(r.prize)}" placeholder="<b>$2.000.000</b>"
            data-on-input="updateXsecRow" data-input-1="s:${s.id}" data-input-2="j:${ri}" data-input-3="s:prize" data-input-4="value"
            style="flex:1;padding:5px 7px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.09);border-radius:5px;color:var(--text);font-size:11px;font-family:inherit;">
          <button data-on-click="removeXsecRow" data-click-1="s:${s.id}" data-click-2="j:${ri}"
            style="flex-shrink:0;width:22px;height:22px;background:rgba(220,50,50,0.1);border:1px solid rgba(220,50,50,0.3);border-radius:5px;color:#e74c3c;cursor:pointer;font-size:11px;">✕</button>
        </div>`).join('');
      return `
        <div class="xsec-card" style="border-left:3px solid var(--accent);">
          <div class="xsec-head">
            <span><i class="fa-solid fa-trophy" style="color:var(--accent);margin-right:5px;font-size:10px;"></i>Tabla de premios ${idx + 1}</span>
            <button class="xsec-del" data-on-click="removeExtraSec" data-click-1="s:${s.id}" title="Eliminar tabla">✕</button>
          </div>
          <div class="field">
            <label>Encabezado (acepta HTML como &lt;b&gt;)</label>
            <input type="text" value="${eh(s.header)}" placeholder="💰 Pozo total $6.500.000..."
              data-on-input="updateExtraSec" data-input-1="s:${s.id}" data-input-2="s:header" data-input-3="value">
          </div>
          <div class="field">
            <label style="display:flex;justify-content:space-between;align-items:center;">
              <span>Filas (posición · premio)</span>
              <button data-on-click="addXsecRow" data-click-1="s:${s.id}" style="font-size:10px;padding:3px 8px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:5px;color:var(--green);cursor:pointer;font-family:inherit;">＋ Fila</button>
            </label>
            <div style="margin-top:4px;">${rowsHtml}</div>
            <div style="font-size:9px;color:var(--text3);margin-top:4px;">Tip: usá <code>&lt;b&gt;$1.000.000&lt;/b&gt;</code> para destacar el premio.</div>
          </div>
        </div>`;
    }

    // Sección estándar
    return `
      <div class="xsec-card">
        <div class="xsec-head">
          <span>Sección ${idx + 1}</span>
          <button class="xsec-del" data-on-click="removeExtraSec" data-click-1="s:${s.id}" title="Eliminar sección">✕</button>
        </div>
        <div class="field">
          <label>Imagen</label>
          <div class="xsec-thumb-btn${s.imgUrl ? ' has-img' : ''}" data-on-click="openExtraSecImgModal" data-click-1="s:${s.id}">
            ${s.imgUrl
              ? `<img src="${s.imgUrl}">`
              : `<div class="xsec-thumb-ph"><i class="fa-solid fa-image" style="color:rgba(34,197,94,0.7);margin-right:4px;"></i><b style="color:rgba(34,197,94,0.7);">Seleccionar</b><br>Subir o elegir guardada</div>`}
          </div>
          ${s.imgExp ? `<div style="font-size:9px;margin-top:3px;font-family:monospace;color:var(--text3);word-break:break-all;">${s.imgExp}</div>` : ''}
        </div>
        <div class="field">
          <label>Título</label>
          <input type="text" value="${eh(s.title)}" placeholder="Lunes Sin Parar"
            data-on-input="updateExtraSec" data-input-1="s:${s.id}" data-input-2="s:title" data-input-3="value">
        </div>
        <div class="field">
          <label>Texto</label>
          <textarea rows="3" placeholder="Texto descriptivo de la sección..."
            data-on-input="updateExtraSec" data-input-1="s:${s.id}" data-input-2="s:text" data-input-3="value"
            style="width:100%;padding:7px 10px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.09);border-radius:var(--rsm);color:var(--text);font-size:12px;font-family:inherit;outline:none;resize:vertical;min-height:60px;">${eh(s.text)}</textarea>
        </div>
        <div class="field">
          <label>Texto del botón</label>
          <input type="text" value="${eh(s.ctaText)}" placeholder="Ver Más"
            data-on-input="updateExtraSec" data-input-1="s:${s.id}" data-input-2="s:ctaText" data-input-3="value">
        </div>
        <div class="field">
          <label>Link del botón</label>
          <div style="display:flex;gap:5px;">
            <input type="url" value="${eh(s.ctaUrl)}" placeholder="https://..."
              data-on-input="updateExtraSec" data-input-1="s:${s.id}" data-input-2="s:ctaUrl" data-input-3="value" style="flex:1;"
              id="xsec_url_${s.id}">
            <button class="btn btn-sm" data-on-click="openLinkGen" data-click-1="s:xsec_url_${s.id}"
              style="flex-shrink:0;border-color:rgba(255,72,0,0.3);color:var(--accent);"><i class="fa-solid fa-link"></i></button>
          </div>
        </div>
      </div>
    `;
  }).join('');
}


function init(){
  buildTB('tb1','ed1');
  buildTB('tb2','ed2');
  mmBuildPlatUI();
  document.getElementById('inpH1').value       = '¡FREEBET HASTA $60.000!';
  document.getElementById('inpGreeting').value  = 'Hola [CustomerFirstName],';
  document.getElementById('inpCtaText').value   = 'Ver Más';
  document.getElementById('inpTnc').value       = '**Aplican Términos y Condiciones. Revisar cuotas, liquidación, tipos de apuestas y condiciones de esta campaña desde la página promocional.';
  document.getElementById('ed1').innerHTML      = (MMP.sample && MMP.sample.body1) || '';
  document.getElementById('ed2').innerHTML      = '<b>¿Cómo funciona?</b><br>📌 Realizá un depósito <b>mínimo de $5.000</b><br>📌 Recibí el <b>100% de tu depósito</b> en Freebet de <b>hasta $60.000</b><br>📌 Tu Freebet estará automáticamente disponible luego de tu carga<br>📌 Campaña disponible solo el <b>19 de abril</b>';
  loadPlat(curPlat);
  update();
}

// ════════════════════════════════════════════════════════════
// PLATAFORMA
// ════════════════════════════════════════════════════════════
// Tarjetas del selector, proyectos del generador de links y textos de ejemplo: salen de los datos (paquete o ejemplo)
function mmBuildPlatUI(){
  mmRenderPlatCards(document.getElementById('platCards'), MMP, curPlat);
  const grid=document.getElementById('lgProjGrid');
  if(grid){
    grid.textContent='';
    MMP.lgProjects.forEach(p=>{
      const b=document.createElement('button');
      b.className='lg-proj-btn';
      b.dataset.domain=p.domain;
      b.setAttribute('data-on-click','lgSetProject');
      b.setAttribute('data-click-1','s:'+p.domain);
      b.textContent=p.label||p.domain;
      grid.appendChild(b);
    });
  }
  ['inpImgUrl2','inpCtaUrl'].forEach(id=>{ const el=document.getElementById(id); if(el) el.placeholder=MMP.urlPlaceholder||''; });
  const tn=document.getElementById('tplName'); if(tn) tn.placeholder=MMP.tplPlaceholder||'';
}
function selectPlat(el, plat) {
  document.querySelectorAll('.plat-card').forEach(c=>c.classList.remove('active'));
  el.classList.add('active');
  // Color de acento de la interfaz: el de la tarjeta (uiBrand) o el naranja de siempre
  const card = MMP.cardById[plat] || {};
  document.documentElement.style.setProperty('--brand', card.uiBrand || '#ff6600');
  document.documentElement.style.setProperty('--brand-dim', card.uiBrandDim || 'rgba(255,102,0,.1)');
  curPlat=plat; loadPlat(plat); update();
}
function loadPlat(plat) {
  const c=PC[plat];
  document.getElementById('platBadge').textContent=c.short;
  const L=c.links;
  document.getElementById('linkMain').value  = L.main||'';
  document.getElementById('linkClub').value  = L.club||'';
  document.getElementById('linkPago').value  = L.pago||'';
  document.getElementById('linkIG').value    = L.ig||'';
  document.getElementById('linkFB').value    = L.fb||'';
  document.getElementById('linkX').value     = L.x||'';
  document.getElementById('linkWA').value    = L.wa||'';
  document.getElementById('linkTT').value    = L.tt||'';
  document.getElementById('linkYT').value    = L.yt||'';
  document.getElementById('linkTG').value    = L.tg||'';
  document.getElementById('inpCtaUrl').value = L.main||'';
}

function toggleAcc(id){
  document.getElementById(id).classList.toggle('open');
  document.getElementById(id+'Body').classList.toggle('open');
}

// ════════════════════════════════════════════════════════════
// IMAGEN
// ════════════════════════════════════════════════════════════
function openImgModal(){
  document.getElementById('imgModal').classList.add('open');
  pendingFile=null;
  document.getElementById('uploadPrev').style.display='none';
  document.getElementById('uploadNm').style.display='none';
  document.getElementById('uploadNm').textContent='';
  document.getElementById('uploadSt').textContent='';
  document.getElementById('btnUpload').disabled=true;
  document.getElementById('imgFileInp').value='';
  switchImgTab('upload');
}
function closeImgModal(){_xsecImgTarget=null;document.getElementById('imgModal').classList.remove('open');}
document.getElementById('imgModal').addEventListener('click',function(e){if(e.target===this)closeImgModal();});
function switchImgTab(tab){
  ['upload','gallery'].forEach(t=>{
    document.getElementById('itab-'+t).classList.toggle('active',t===tab);
    document.getElementById('ipanel-'+t).classList.toggle('active',t===tab);
  });
  if(tab==='gallery')loadGal();
}
function handleDrop(e){
  e.preventDefault();document.getElementById('dzImg').classList.remove('over');
  const f=e.dataTransfer.files[0];if(!f)return;
  const inp=document.getElementById('imgFileInp');
  const dt=new DataTransfer();dt.items.add(f);inp.files=dt.files;
  onFileSelect(inp);
}
function onFileSelect(inp){
  const f=inp.files[0];if(!f)return;
  if(f.size>5*1024*1024){setSt('uploadSt','⚠ El archivo supera 5MB','var(--red)');return;}
  pendingFile=f;
  const r=new FileReader();
  r.onload=e=>{
    const p=document.getElementById('uploadPrev');p.src=e.target.result;p.style.display='block';
    const n=document.getElementById('uploadNm');n.textContent=f.name;n.style.display='block';
    document.getElementById('btnUpload').disabled=false;
    document.getElementById('uploadSt').textContent='';
  };
  r.readAsDataURL(f);
}
async function doUpload(){
  if(!pendingFile)return;
  const btn=document.getElementById('btnUpload');
  btn.disabled=true;btn.textContent='Subiendo...';
  setSt('uploadSt','Subiendo al servidor...','var(--text2)');
  try{
    const fd=new FormData();fd.append('file',pendingFile);fd.append('type','mailing');
    const r=await fetch('proxy.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.error)throw new Error(d.error);
    const absUrl=toAbsUrl(d.url);
    if(_xsecImgTarget){
      setExtraSecImg(_xsecImgTarget, absUrl, d.url);
      _xsecImgTarget = null;
    } else {
      setImgUrl(absUrl, d.url);
    }
    document.getElementById('uploadSt').innerHTML='✓ Subida · <span style="font-family:monospace;font-size:10px;color:var(--brand);">'+d.url+'</span>';
    galItems.unshift({url:d.url,absUrl,name:pendingFile.name});
    pendingFile=null;
    setTimeout(closeImgModal,1600);
  }catch(err){
    setSt('uploadSt','⚠ Error: '+err.message,'var(--red)');
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-cloud-arrow-up" style="margin-right:5px;"></i>Subir al servidor';
  }
}
function setSt(id,msg,color){const el=document.getElementById(id);el.style.color=color;el.textContent=msg;}

// ════════════════════════════════════════════════════════════
// GALERÍA
// ════════════════════════════════════════════════════════════
async function loadGal(){
  document.getElementById('galLoading').style.display='block';
  document.getElementById('galGrid').style.display='none';
  document.getElementById('galEmpty').style.display='none';
  galSelected=null;
  document.getElementById('btnGalApply').disabled=true;
  document.getElementById('galSelInfo').textContent='Ninguna seleccionada';
  try{
    const r=await fetch('proxy.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list_imgs',type:'mailing'})});
    const d=await r.json();
    galItems=(d.files||[]).map(f=>({
      url:f.url,           // ruta relativa del servidor (para delete y export)
      absUrl:toAbsUrl(f.url), // URL absoluta para preview e img src
      name:f.name
    }));
  }catch(e){}
  document.getElementById('galLoading').style.display='none';
  renderGal(galItems);
}
function renderGal(items){
  const grid=document.getElementById('galGrid'),empty=document.getElementById('galEmpty');
  if(!items.length){grid.style.display='none';empty.style.display='block';return;}
  empty.style.display='none';grid.style.display='grid';
  grid.innerHTML=items.map((item,i)=>`
    <div class="gal-item${galSelected&&galSelected.url===item.url?' selected':''}" data-on-click="selectGal" data-click-1="j:${i}" data-idx="${i}">
      <div class="gal-img-wrap">
        <img class="gal-img" src="${item.absUrl||item.url}" alt="${eh(item.name)}" loading="lazy" data-on-error="mmGalImgErr" data-error-1="this">
      </div>
      <div class="gal-check">✓</div>
      <div class="gal-nm">${eh(item.name)}</div>
      <button class="gal-del" data-on-click="delGalItem" data-click-1="s:${item.url}" data-click-2="j:${i}">✕</button>
    </div>`).join('');
}
function filterGal(q){renderGal(!q?galItems:galItems.filter(it=>it.name.toLowerCase().includes(q.toLowerCase())));}
function selectGal(idx){
  galSelected=galItems[idx];
  document.querySelectorAll('.gal-item').forEach(el=>el.classList.toggle('selected',parseInt(el.dataset.idx)===idx));
  document.getElementById('galSelInfo').textContent='Seleccionada: '+galSelected.name;
  document.getElementById('btnGalApply').disabled=false;
}
function applyGal(){
  if(!galSelected)return;
  // Si hay target de sección extra → aplicar ahí
  if(_xsecImgTarget){
    setExtraSecImg(_xsecImgTarget, galSelected.absUrl||galSelected.url, galSelected.url);
    _xsecImgTarget = null;
  } else {
    // galSelected.absUrl para preview, galSelected.url para export
    setImgUrl(galSelected.absUrl||galSelected.url, galSelected.url);
  }
  closeImgModal();
}
async function delGalItem(serverUrl,idx){
  if(!confirm('¿Eliminar esta imagen del servidor?'))return;
  try{await fetch('proxy.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_img',url:serverUrl})});}catch(e){}
  galItems.splice(idx,1);
  if(galSelected&&galSelected.url===serverUrl){galSelected=null;document.getElementById('btnGalApply').disabled=true;document.getElementById('galSelInfo').textContent='Ninguna seleccionada';}
  renderGal(galItems);
}

// URL pública de esta carpeta (las imágenes subidas quedan en imgs/): la del paquete de marcas o la de esta página
const SERVER_BASE = MMP.serverBase;

// imgUrl     = URL absoluta para preview
// imgExport  = URL absoluta final para el HTML exportado
let imgExport = '';

function toAbsUrl(url){
  if(!url)return'';
  if(/^https?:\/\//i.test(url)||/^data:/.test(url))return url;
  return SERVER_BASE + url.replace(/^\.\//,'');
}

function buildExportImgUrl(serverRelUrl){
  if(!serverRelUrl)return'';
  if(/^https?:\/\//i.test(serverRelUrl))return serverRelUrl;
  return SERVER_BASE + serverRelUrl.replace(/^\.\//,'');
}

function setImgUrl(absUrl, serverUrl){
  imgUrl    = absUrl;
  imgExport = buildExportImgUrl(serverUrl||absUrl);
  const thumb=document.getElementById('imgThumb');
  const placeholder=document.getElementById('imgPlaceholder');
  const btn=document.getElementById('imgSelectorBtn');
  thumb.src=absUrl; thumb.style.display='block';
  placeholder.style.display='none';
  btn.classList.add('has-img');
  // Mostrar URL final que quedará en el HTML exportado
  let info=document.getElementById('imgPathInfo');
  if(!info){
    info=document.createElement('div');info.id='imgPathInfo';
    info.style.cssText='font-size:10px;margin-top:5px;font-family:monospace;word-break:break-all;';
    document.getElementById('imgSelectorBtn').insertAdjacentElement('afterend',info);
  }
  info.innerHTML='<span style="color:var(--text3);">URL export: </span><span style="color:var(--brand);">'+imgExport+'</span>';
  update();
}

// ════════════════════════════════════════════════════════════
// VALORES DEL FORM
// ════════════════════════════════════════════════════════════
function G(id){return document.getElementById(id).value;}
function V(){
  const c=PC[curPlat];
  return{
    c,
    h1:G('inpH1'),
    greeting:G('inpGreeting'),
    body1:document.getElementById('ed1').innerHTML,
    body2:document.getElementById('ed2').innerHTML,
    tnc:G('inpTnc'),
    ctaText:G('inpCtaText')||'Ver Más',
    ctaUrl:G('inpCtaUrl')||c.links.main,
    links:{
      main:G('linkMain')||c.links.main, club:G('linkClub')||c.links.club,
      pago:G('linkPago')||c.links.pago,
      ig:G('linkIG'), fb:G('linkFB'), x:G('linkX'),
      wa:G('linkWA'), tt:G('linkTT'), yt:G('linkYT'), tg:G('linkTG'),
    },
    img:imgUrl,        // URL absoluta → para preview
    imgExp:imgExport,  // URL del servidor → para HTML exportado
    imgLink:getImgLink(), // link que va en el href de la imagen
  };
}

// ════════════════════════════════════════════════════════════
// PREVIEW — fiel al template real de cada plataforma
// ════════════════════════════════════════════════════════════
function update(){
  const v=V(), c=v.c;

  // Imagen principal
  const imgBlock=v.img
    ?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;">
       <a href="${xe(v.imgLink)}" target="_blank">
         <img src="${v.img}" alt="" style="width:100%;border-radius:1em;margin:0 0 1rem;border:0;outline:none;display:block;">
       </a></div></div>`
    :`<div style="width:100%;height:90px;border-radius:1em;margin-bottom:1rem;background:linear-gradient(135deg,#141414,#0f0f0f);display:flex;align-items:center;justify-content:center;font-family:'Roboto Condensed',Arial,sans-serif;font-size:12px;color:rgba(255,255,255,0.3);letter-spacing:1px;text-transform:uppercase;">SELECCIONÁ UNA IMAGEN</div>`;

  // Redes: imágenes reales del servidor
  const snHtml=c.socialSet.map(sId=>{
    const href=v.links[sId];if(!href)return'';
    const file=c.socialNames[sId];
    return`<td style="padding:0 4px;"><a href="${xe(href)}" target="_blank"><img src="${c.iconsBase}${file}.jpg" alt="${sId.toUpperCase()}" width="32" style="display:block;border:0;outline:none;height:auto;max-width:32px;" data-on-error="mmHideImg" data-error-1="this"></a></td>`;
  }).join('');

  // Bloque métodos de pago
  const pagosBlock=c.hasMetodosPago
    ?(c.paymentNoBg
      ?`<div style="display:table;width:100%;text-align:center;">
          <div style="display:table-cell;width:100%;text-align:center;">
            <a href="${xe(v.links.pago||v.links.main)}" target="_blank">
              <img src="${c.paymentUrl}" style="outline:none;border:0;max-width:100%;" alt="Métodos de pago" data-on-error="mmHideImg" data-error-1="this">
            </a>
          </div>
        </div>`
      :`<div style="display:table;width:100%;border-radius:.5em;background-color:#F2F2F2;box-shadow:0 4px 4px 0 rgba(0,0,0,0.1);">
          <div style="display:table-cell;width:100%;text-align:center;">
            <a href="${xe(v.links.pago||v.links.main)}" target="_blank">
              <img src="${c.paymentUrl}" style="outline:none;border:0;width:85%;" alt="Métodos de pago" data-on-error="mmHideImg" data-error-1="this">
            </a>
          </div>
        </div>`)
    : '';

  // Bloque club/soporte
  let clubBlock='';
  if(c.hasClub){
    if(c.clubIsSimple){
      // PY: tiene h4 + link + imagen soporte (más simple)
      clubBlock=`<div style="display:table;background:#FF4800;width:100%;border-radius:.5em;box-shadow:0 4px 4px 0 rgba(0,0,0,0.1);">
        <div style="display:table-cell;width:20%;padding:0 0 0 1em;vertical-align:middle;text-align:right;">
          <img src="${c.supportUrl}" style="outline:none;border:0;width:32px;height:32px;margin-right:8px;" alt="Soporte" data-on-error="mmHideImg" data-error-1="this">
        </div>
        <div style="display:table-cell;width:80%;padding:1em .5em;vertical-align:top;">
          <h4 style="color:#FFFFFF;padding:0;margin:0 0 .25em;line-height:1;font-family:'Roboto Condensed',Arial,sans-serif;">Soporte 24/7</h4>
          <a href="${xe(v.links.club)}" target="_blank" style="color:#FFF;font-weight:normal;font-size:.9em;line-height:.9em;margin:0;padding:0;text-decoration:none;">${c.clubLabel}</a>
        </div>
      </div>`;
    } else {
      // 777: tabla con imagen soporte 48px + img input-alc.jpg
      clubBlock=`<div style="display:table;width:100%;background:#FF4800;border-radius:.5em;padding:.5em 0;">
        <div style="display:table;width:100%">
          <div style="display:table-cell;width:28%;padding:0;vertical-align:middle;text-align:right;">
            <a href="${xe(v.links.club)}" target="_blank">
              <img src="${c.supportUrl}" style="outline:none;border:0;width:48px;height:48px;margin-right:4px;vertical-align:middle;" alt="Soporte" data-on-error="mmHideImg" data-error-1="this">
            </a>
          </div>
          <div style="display:table-cell;width:68%;padding:.5em .25em .5em;line-height:1.3;vertical-align:middle;">
            <a href="${xe(v.links.club)}" target="_blank" style="font-size:.9em;line-height:.9em;color:#FFFFFF;text-align:left;text-decoration:none;padding:0;margin:0;">${c.clubLabel}</a>
          </div>
        </div>
        <div style="display:table;width:100%;">
          <div style="display:table-cell;width:100%;text-align:center;vertical-align:middle;padding:0;margin:0 0 .25em;">
            <a href="${xe(v.links.club)}" target="_blank" style="margin-bottom:0;display:block;">
              <img src="${c.clubBtnUrl}" width="220" height="34" style="display:block;max-width:220px;height:auto;margin:0 auto .25em;border:0;outline:none;" alt="${c.clubBtnLbl}" data-on-error="mmHideImg" data-error-1="this">
            </a>
          </div>
        </div>
      </div>`;
    }
  }

  // Footer con URL (GT)
  const footerUrlBlock=c.hasFooterUrl
    ?`<div style="display:table;width:100%;text-align:center;border-top:1px solid #ddd;">
        <a href="${xe(v.links.main)}" style="color:#999;margin:1em 0 0;font-size:.8em;line-height:1em;text-align:center;text-decoration:none;display:block;font-family:'Roboto Condensed',Arial,sans-serif;">
          ${c.footerUrlText||v.links.main}
        </a>
      </div>` : '';

  // Disclaimer externo
  const disclaimerBlock=c.hasDisclaimer
    ?`<div style="max-width:30em;margin:0 auto;background-color:transparent;padding:0;">
        <p style="padding:1em 0;font-size:.8em;line-height:1em;color:#999;text-align:center;font-family:'Roboto Condensed',Arial,sans-serif;">
          ${c.disclaimerText}
        </p>
      </div>` : '';

  // Espaciadores (1 o 2 antes de métodos pago si lo hay)
  const spacer=`<div style="height:16px;"></div>`;

  // Header negro o no
  const headerHtml=c.hasHeaderBlack
    ?`<div style="background:#000;text-align:center;padding:0;">
        <a href="${xe(v.links.main)}" target="_blank">
          ${mmLogo(c, '#fff', `<img src="${c.logoUrl}" alt="${c.name}" width="${c.logoWidth}"
            style="width:${c.logoWidth}px;border:0;outline:none;margin:${c.logoMargin};display:inline-block;"
            data-on-error="mmSwapHtml" data-error-1="this" data-error-2="s:<span style=font-family:Roboto Condensed,Arial,sans-serif;font-size:18px;font-weight:800;color:#fff;padding:1em;display:block;>${c.name}</span>">`)}
        </a>
      </div>`
    :'';

  // Hero con imagen de fondo (rebrand) — H1 y saludo van dentro de la imagen
  // La imagen principal del formulario reemplaza la imagen de portada por defecto
  const _heroBg = v.img || c.heroBgUrl;
  const heroBgHtml=c.hasHeroBg
    ?`<div style="max-width:32em;margin:0 auto;background:#fff;">
        <div style="display:table;width:100%;">
          <div style="display:table-cell;width:100%;height:360px;padding:1.5em 1em 1em;text-align:center;vertical-align:top;background:url(${_heroBg}) no-repeat bottom center;">
            <p style="color:${c.h1Color};font-size:1.8em;font-weight:bold;padding:0 0 .25em;margin:0;font-family:'Roboto Condensed',Arial,sans-serif;">${xe(v.h1)||'(título)'}</p>
            ${v.greeting?`<p style="color:#fff;padding:0;margin:0;line-height:140%;font-family:'Roboto Condensed',Arial,sans-serif;">${xe(v.greeting)}</p>`:''}
          </div>
        </div>
      </div>`
    :'';

  // Logo dentro del container blanco (sin header negro)
  const logoInContainerHtml=!c.hasHeaderBlack
    ?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;text-align:center;">
        <a href="${xe(v.links.main+(c.logoLinkUtm||''))}" target="_blank">
          ${mmLogo(c, '#333', `<img src="${c.logoUrl}" alt="${c.name}" width="${c.logoWidth}"
            style="width:${c.logoWidth}px;border:0;outline:none;margin:${c.logoMargin};"
            data-on-error="mmSwapHtml" data-error-1="this" data-error-2="s:<span style=font-family:Roboto Condensed,Arial,sans-serif;font-size:18px;font-weight:800;color:#333;>${c.name}</span>">`)}
        </a>
      </div></div>`
    :'';

  document.getElementById('mlPrev').innerHTML=`
    <div style="background:#f7f8f9;">
      ${headerHtml}
      ${heroBgHtml}
      <div style="max-width:30em;margin:0 auto;padding:${c.hasHeaderBlack?'1em 1em 2em':'2em 1em'};background-color:#FFFFFF;">
        ${logoInContainerHtml}

        ${c.hasHeroBg ? '' : `<!-- H1 -->
        <div style="display:table;width:100%;"><div style="display:table-cell;width:100%;text-align:center;">
          <h1 style="margin:0 0 .5em;font-family:'Roboto Condensed',Arial,sans-serif;font-size:24px;line-height:1.25em;color:${c.h1Color};">
            ${xe(v.h1)||'(título)'}
          </h1>
        </div></div>`}

        ${c.hasHeroBg ? '' : imgBlock}

        ${c.hasHeroBg ? '' : v.greeting?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;"><h2 style="margin:0 0 .5em;font-size:1.25em;line-height:1.25em;font-weight:bold;color:#111111;text-align:left;font-family:'Roboto Condensed',Arial,sans-serif;">${xe(v.greeting)}</h2></div></div>`:'' }

        <!-- Cuerpo -->
        ${v.body1?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;"><p style="margin:0 0 1em;font-size:.9em;line-height:1.5em;color:#111111;text-align:${c.bodyAlign||'left'};font-family:'Roboto Condensed',Arial,sans-serif;">${v.body1}</p></div></div>`:''}
        ${v.body2?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;"><p style="margin:0 0 1em;font-size:.9em;line-height:1.5em;color:#111111;text-align:${c.bodyAlign||'left'};font-family:'Roboto Condensed',Arial,sans-serif;">${v.body2}</p></div></div>`:''}
        ${v.tnc?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;"><p style="margin:0 0 1em;font-size:.7em;font-style:italic;line-height:1.25em;color:#666;text-align:left;">${xe(v.tnc)}</p></div></div>`:''}

        <!-- CTA -->
        <div style="display:table;width:100%;"><div style="display:table-cell;width:100%;text-align:center;">
          <a href="${xe(v.ctaUrl)}" target="_blank"
            style="background-color:${c.ctaColor};text-decoration:none;${c.ctaExtraCss||'text-transform:uppercase;font-size:1.15em;font-weight:bold;border-radius:.5em;'}padding:.5em 1em;color:${c.ctaTextColor};display:inline-block;font-family:'Roboto Condensed',Arial,sans-serif;">
            ${xe(v.ctaText)}
          </a>
        </div></div>

        ${genBonusCardsPreview()}

        ${extraSecs.map(s => {
          if(s.kind === 'table'){
            const rowsP = (s.rows||[]).map((r,i) => {
              const bg = i % 2 === 0 ? '#f7f8f9' : '#fff';
              return `<div style="display:table;width:100%;background-color:${bg};">
                <div style="display:table-cell;width:40%;padding-right:1em;"><p style="margin:0 0 .25em;font-size:.9em;line-height:1.5em;color:#999;text-align:right;"><b>${xe(r.pos)}</b></p></div>
                <div style="display:table-cell;width:60%;"><p style="margin:0 0 .25em;font-size:.9em;line-height:1.5em;color:#111111;text-align:left;">${r.prize||''}</p></div>
              </div>`;
            }).join('');
            return `
              ${spacer}
              ${s.header ? `<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;"><p style="margin:0 0 .5em;font-size:1em;line-height:1.5em;color:#111111;text-align:left;font-family:'Roboto Condensed',Arial,sans-serif;">${s.header}</p></div></div>` : ''}
              <div style="display:table;width:100%;"><div style="display:table-cell;width:100%;">${rowsP}</div></div>`;
          }
          // estándar
          return `
          ${spacer}
          <div style="display:table;width:100%;">
            <div style="display:table-cell;width:30%;vertical-align:top;">
              ${s.imgUrl ? `<a href="${xe(s.ctaUrl||'#')}" style="display:block;margin-right:.75em;"><img src="${xe(s.imgUrl)}" width="100%" height="auto" style="margin:0;border:0;outline:none;border-radius:1em;max-width:100%;height:auto;"></a>` : `<div style="margin-right:.75em;border-radius:1em;background:#e9e9e9;aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#888;font-size:11px;font-style:italic;">Sin imagen</div>`}
            </div>
            <div style="display:table-cell;width:70%;vertical-align:top;">
              <h3 style="margin:0 0 .25em;font-size:1.15em;line-height:1.5em;font-weight:bold;color:#111111;text-align:left;font-family:'Roboto Condensed',Arial,sans-serif;">${xe(s.title)}</h3>
              <p style="margin:0 0 .5em;font-size:.9em;line-height:1.5em;color:#111111;text-align:left;font-family:'Roboto Condensed',Arial,sans-serif;">${xe(s.text).replace(/\n/g,'<br>')}</p>
              <a href="${xe(s.ctaUrl||'#')}" style="background-color:${c.ctaColor};text-decoration:none;text-transform:uppercase;font-size:.9em;font-weight:bold;padding:.5em 1em;margin:0;color:${c.ctaTextColor};display:inline-block;border-radius:.5em;font-family:'Roboto Condensed',Arial,sans-serif;">${xe(s.ctaText)}</a>
            </div>
          </div>`;
        }).join('')}

        ${spacer}
        ${pagosBlock}
        ${pagosBlock?spacer:''}
        ${clubBlock}
        ${clubBlock?spacer:''}

        <!-- Redes -->
        ${snHtml?`<div style="display:table;width:100%;text-align:center;">
          <table border="0" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto;width:auto;">
            <tr>${snHtml}</tr>
          </table>
        </div>`:''}

        ${c.hasFooterUrl?spacer:''}
        ${footerUrlBlock}
      </div>
      ${disclaimerBlock}
    </div>`;
}

// ════════════════════════════════════════════════════════════
// GENERAR HTML EXPORTABLE — fiel al template real
// ════════════════════════════════════════════════════════════
function edClean(h){
  // Convertir <font color="..."> en <span style="color:..."> para el export
  h = h.replace(/<font[^>]*color=["']([^"']+)["'][^>]*>([\s\S]*?)<\/font>/gi,
    (_, color, content) => `<span style="color:${color}">${content}</span>`);
  // Limpiar font sin color
  h = h.replace(/<font[^>]*>/gi,'').replace(/<\/font>/gi,'');
  return h
    .replace(/<div>/gi,'').replace(/<\/div>/gi,'<br>')
    .replace(/<span(?![^>]*style)[^>]*>/gi,'').replace(/<\/span>/gi,'')
    .replace(/<p>/gi,'').replace(/<\/p>/gi,'<br>')
    .replace(/(<br\s*\/?>\s*){3,}/gi,'<br><br>')
    .replace(/^(<br\s*\/?>)+/,'').replace(/(<br\s*\/?>)+$/,'');
}

function gen(){
  const v=V(), c=v.c;
  const b1=edClean(v.body1), b2=edClean(v.body2);

  const spacerImg=`<div style="display:table;width:100%;height:20px;background:transparent;">${c.spacer?`<img src="${c.spacer}" style="outline:none;border:0;margin:0;padding:0;background:transparent;" alt="">`:''}</div>`;

  const imgSrc = buildExportImgUrl(v.imgExp||v.img);
  const imgBlock=imgSrc
    ?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;">
        <a href="${xe(v.imgLink)}" target="_blank">
          <img src="${xe(imgSrc)}" alt="" style="width:100%;border-radius:1em;margin:0 0 1rem;border:0;outline:none;">
        </a></div></div>` : `<!-- Sin imagen principal -->`;

  const socialTds=c.socialSet.map(sId=>{
    const href=v.links[sId];if(!href)return'';
    const file=c.socialNames[sId];
    return`<td style="padding:0 4px;"><a href="${xe(href)}" target="_blank"><img src="${c.iconsBase}${file}.jpg" alt="${sId.toUpperCase()}" width="32" style="display:block;border:0;outline:none;height:auto;max-width:32px;"></a></td>`;
  }).join('');

  // Métodos de pago
  const pagosHtml=c.hasMetodosPago
    ?(c.paymentNoBg
      ?`${spacerImg}
    <div style="display:table;width:100%;text-align:center;">
      <div style="display:table-cell;width:100%;text-align:center;">
        <a href="${xe(v.links.pago||v.links.main)}" target="_blank">
          <img src="${c.paymentUrl}" style="outline:none;border:0;max-width:100%;" alt="Métodos de pago">
        </a>
      </div>
    </div>`
      :`${spacerImg}
    ${spacerImg}
    <div style="display:table;width:100%">
      <div style="display:table;width:100%;border-radius:.5em;background-color:#F2F2F2;box-shadow:0 4px 4px 0 rgba(0,0,0,0.1);-webkit-box-shadow:0 4px 4px 0 rgba(0,0,0,0.1);-moz-box-shadow:0 4px 4px 0 rgba(0,0,0,0.1);">
        <div style="display:table-cell;width:100%;text-align:center;">
          <a href="${xe(v.links.pago||v.links.main)}" target="_blank">
            <img src="${c.paymentUrl}" style="outline:none;border:0;width:85%;" alt="Métodos de pago">
          </a>
        </div>
      </div>
    </div>`)
    : '';

  // Club / soporte
  let clubHtml='';
  if(c.hasClub){
    if(c.clubIsSimple){
      clubHtml=`${spacerImg}
      <div style="display:table;width:100%">
        <div style="display:table;background:#FF4800;width:100%;border-radius:.5em;box-shadow:0 4px 4px 0 rgba(0,0,0,0.1);-webkit-box-shadow:0 4px 4px 0 rgba(0,0,0,0.1);-moz-box-shadow:0 4px 4px 0 rgba(0,0,0,0.1);">
          <div style="display:table-cell;width:20%;padding:0 0 0 1em;vertical-align:middle;text-align:right;">
            <img src="${c.supportUrl}" style="outline:none;border:0;width:32px;height:32px;margin-right:8px;">
          </div>
          <div style="display:table-cell;width:80%;padding:1em .5em;vertical-align:top;">
            <h4 style="color:#FFFFFF;padding:0;margin:0 0 .25em;line-height:1;">Soporte 24/7</h4>
            <a href="${xe(v.links.club)}" target="_blank" style="color:#FFF;font-weight:normal;font-size:.9em;line-height:.9em;margin:0;padding:0;text-decoration:none!important;">${c.clubLabel}</a>
          </div>
        </div>
      </div>`;
    }else{
      clubHtml=`${spacerImg}
      <div style="display:table;width:100%;background:#FF4800;border-radius:.5em;padding:.5em 0;">
        <div style="display:table;width:100%">
          <div style="display:table-cell;width:28%;padding:0;vertical-align:middle;text-align:right;">
            <a href="${xe(v.links.club)}" target="_blank">
              <img src="${c.supportUrl}" style="outline:none;border:0;width:48px;height:48px;margin-top:0;margin-right:4px;vertical-align:middle;" alt="Soporte">
            </a>
          </div>
          <div style="display:table-cell;width:68%;padding:.5em .25em .5em;line-height:1.3;vertical-align:middle;">
            <a href="${xe(v.links.club)}" target="_blank" style="font-size:.9em;line-height:.9em;color:#FFFFFF;text-align:left;text-decoration:none;padding:0;margin:0;">${c.clubLabel}</a>
          </div>
        </div>
        <div style="display:table;width:100%;">
          <div style="display:table-cell;width:100%;text-align:center;vertical-align:middle;padding:0;margin:0 0 .25em;">
            <a href="${xe(v.links.club)}" target="_blank" style="margin-bottom:0;display:block;">
              <img src="${c.clubBtnUrl}" width="220" height="34" style="display:block;max-width:220px;height:auto;margin:0 auto .25em;border:0;outline:none;" alt="${c.clubBtnLbl}">
            </a>
          </div>
        </div>
      </div>`;
    }
  }

  const footerUrlHtml=c.hasFooterUrl
    ?`${spacerImg}
    <div style="display:table;width:100%;text-align:center;border-top:1px solid #ddd;">
      <a href="${xe(v.links.main)}" style="color:#999;margin:1em 0 0;font-size:.8em;line-height:1em;text-align:center;text-decoration:none;display:block;">${c.footerUrlText||v.links.main}</a>
    </div>` : '';

  const disclaimerHtml=c.hasDisclaimer
    ?`<div style="max-width:30em;margin:0 auto;background-color:transparent;padding:0;">
        <p style="padding:1em 0;font-size:.8em;line-height:1em;color:#999;text-align:center;">${c.disclaimerText}</p>
      </div>` : '';

  const headerHtml=c.hasHeaderBlack
    ?`<div style="max-width:32em;margin:0 auto;padding:0;background-color:#000;" bgcolor="#000">
        <div style="display:table;width:100%;"><div style="display:table-cell;width:100%;text-align:center;">
          <a href="${xe(v.links.main)}" target="_blank">
            ${mmLogo(c, '#fff', `<img src="${c.logoUrl}" alt="${c.name}" width="${c.logoWidth}" style="width:${c.logoWidth}px;border:0;outline:none;margin:${c.logoMargin};">`)}
          </a>
        </div></div>
      </div>` : '';

  const _heroBg = buildExportImgUrl(v.imgExp||v.img) || c.heroBgUrl;
  const heroBgHtml=c.hasHeroBg
    ?`<div style="max-width:32em;margin:0 auto;padding:0;background-color:#FFFFFF;">
        <div style="display:table;width:100%;">
          <div style="display:table-cell;width:100%;height:360px;padding:1.5em 1em 1em;text-align:center;vertical-align:top;background:url(${_heroBg}) no-repeat bottom center;">
            <p style="color:${c.h1Color};font-size:2em;font-weight:bold;padding:0 0 .25em;margin:0;font-family:'Roboto Condensed',Arial,Helvetica,sans-serif;">${xe(v.h1)}</p>
            ${v.greeting?`<p style="padding:0 0 .25em;margin:0;line-height:140%;color:#ffffff;font-family:'Roboto Condensed',Arial,Helvetica,sans-serif;">${xe(v.greeting)}</p>`:''}
          </div>
        </div>
      </div>` : '';

  const logoInContainerHtml=!c.hasHeaderBlack
    ?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;text-align:center;">
        <a href="${xe(v.links.main+(c.logoLinkUtm||''))}" target="_blank">
          ${mmLogo(c, '#333', `<img src="${c.logoUrl}" alt="${c.name}" width="${c.logoWidth}" style="width:${c.logoWidth}px;border:0;outline:none;margin:${c.logoMargin};">`)}
        </a>
      </div></div>` : '';

  const containerPadding=c.hasHeaderBlack?'1em 1em 2em':'2em 1rem';

  const _ba=c.bodyAlign||'left';
  const bodyBlock1=b1?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;"><p style="margin:0 0 1em;font-size:.9em;line-height:1.5em;color:#111111;text-align:${_ba};">${b1}</p></div></div>`:'';
  const bodyBlock2=b2?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;"><p style="margin:0 0 1em;font-size:.9em;line-height:1.5em;color:#111111;text-align:${_ba};">${b2}</p></div></div>`:'';
  const tncHtml=v.tnc?`<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;"><p style="margin:0 0 1em;font-size:.7em;font-style:italic;line-height:1.25em;color:#666;text-align:left;">${xe(v.tnc)}</p></div></div>`:'';

  return`<!DOCTYPE html>
<html lang="es" dir="ltr" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
  <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>${xe(v.h1)}</title>
  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
  <![endif]-->
  <style>
    :root{color-scheme:light dark;supported-color-schemes:light dark;}
    body{font-family:'Roboto Condensed',Arial,Helvetica,sans-serif;font-size:2em;color:#f7f8f9;}
    .column{display:block!important;width:100%!important;}
    .mb-1{margin-bottom:1.5em;}
    @media only screen and (max-width:468px){
      .body-mailing{padding:0!important;background-color:#FFFFFF!important;}
      .container-white{padding:1em!important;}
    }
  </style>
</head>
<body class="body" xml:lang="es">
<div role="article" aria-roledescription="email" lang="es" dir="ltr"
  style="font-size:max(16px,0rem);background-color:#f7f8f9;padding:0;" class="body-mailing">

  ${headerHtml}

  ${heroBgHtml}

  <div style="max-width:30em;margin:0 auto;padding:${containerPadding};background-color:#FFFFFF;" class="container-white" bgcolor="#FFFFFF">

    ${logoInContainerHtml}

    ${c.hasHeroBg ? '<!-- H1 y saludo en hero -->' : `<!-- H1 -->
    <div style="display:table;width:100%;"><div style="display:table-cell;width:100%;text-align:center;">
      <h1 style="margin:0 0 .5em;font-family:'Roboto Condensed',Arial,Helvetica,sans-serif;font-size:24px;line-height:1.25em;color:${c.h1Color};">
        <font face="'Roboto Condensed',Arial,Helvetica,sans-serif" color="${c.h1Color}" size="5">${xe(v.h1)}</font>
      </h1>
    </div></div>`}

    ${c.hasHeroBg ? '' : imgBlock}

    ${c.hasHeroBg ? '' : `<div style="display:table;width:100%;"><div style="display:table-cell;width:100%;">
      <h2 style="margin:0 0 .5em;font-size:1.25em;line-height:1.25em;font-weight:bold;color:#111111;text-align:left;">${xe(v.greeting)}</h2>
    </div></div>`}

    ${bodyBlock1}
    ${bodyBlock2}
    ${tncHtml}

    <!-- CTA -->
    <div style="display:table;width:100%">
      <div style="display:table-cell;width:100%;text-align:center;" class="column">
        <a href="${xe(v.ctaUrl)}"
          style="background-color:${c.ctaColor};text-decoration:none;${c.ctaExtraCss||'text-transform:uppercase;font-size:1.15em;font-weight:bold;border-radius:.5em;'}padding:.5em 1em;margin:0;color:${c.ctaTextColor};display:inline-block;mso-padding-alt:0;text-underline-color:${c.ctaTextColor}"><!--[if mso]><i style="mso-font-width:200%;mso-text-raise:100%" hidden>&emsp;</i><span style="mso-text-raise:50%;"><![endif]-->${xe(v.ctaText)}<!--[if mso]></span><i style="mso-font-width:200%;" hidden>&emsp;&#8203;</i><![endif]--></a>
      </div>
    </div>

    ${genBonusCardsExport()}

    ${extraSecs.map((s,idx) => {
      if(s.kind === 'table'){
        const rowsE = (s.rows||[]).map((r,i) => {
          const bg = i % 2 === 0 ? '#f7f8f9' : '#fff';
          return `          <div style="display:table; width: 100%;">
            <div style="display:table-cell;width:100%">
              <div style="display:table; width: 100%;background-color: ${bg};">
                <div style="display:table-cell;width:40%; padding-right: 1em;">
                  <p style="margin: 0 0 .25em; font-size: .9em; line-height: 1.5em; color: #999; text-align: right;"><b>${xe(r.pos)}</b></p>
                </div>
                <div style="display:table-cell;width:50%">
                  <p style="margin: 0 0 .25em; font-size: .9em; line-height: 1.5em; color: #111111; text-align: left;">${r.prize||''}</p>
                </div>
              </div>
            </div>
          </div>`;
        }).join('\n');
        return `
    ${spacerImg}
    <!-- Inicio: Tabla de premios ${idx+1} -->
    ${s.header ? `<div style="display:table; width: 100%;">
      <div style="display:table-cell;width:100%">
        <p style="margin: 0 0 .5em; font-size: 1em; line-height: 1.5em; color: #111111; text-align: left;">${s.header}</p>
      </div>
    </div>` : ''}
    <div style="display:table; width: 100%;">
      <div style="display:table-cell;width:100%">
${rowsE}
      </div>
    </div>
    <!-- Fin: Tabla de premios ${idx+1} -->`;
      }
      // estándar
      return `
    ${spacerImg}
    <!-- Inicio: Sección ${idx+2} -->
    <div style="display:table; width: 100%;">
      <div style="display:table-cell;width:30%; vertical-align: top;" class="thumb">
        <a href="${xe(s.ctaUrl||'#')}" style="display: block; margin-right: .75em;">
          <img src="${xe(s.imgExp||s.imgUrl||'')}" width="100%" height="auto" style="margin: 0; border: 0; outline: none; border-radius:1em; max-width:100%; height:auto;">
        </a>
      </div>
      <div style="display:table-cell;width:70%; vertical-align: top;" class="col-data">
        <h3 style="margin:0 0 .25em; font-size:1.15em; line-height: 1.5em; font-weight: bold; color: #111111; text-align: left; ">${xe(s.title)}</h3>
        <p style="margin: 0 0 .5em; font-size: .9em; line-height: 1.5em; color: #111111; text-align: left;">${xe(s.text).replace(/\n/g,'<br>')}</p>
        <a href="${xe(s.ctaUrl||'#')}" style="background-color:${c.ctaColor}; text-decoration: none; text-transform: uppercase; font-size: .9em; font-weight: bold; padding: .5em 1em; margin: 0; color: ${c.ctaTextColor}; display:inline-block; border-radius:.5em; mso-padding-alt:0;text-underline-color:${c.ctaTextColor}"><!--[if mso]><i style="mso-font-width:200%;mso-text-raise:100%" hidden>&emsp;</i><span style="mso-text-raise:50%;"><![endif]-->${xe(s.ctaText)}<!--[if mso]></span><i style="mso-font-width:200%;" hidden>&emsp;&#8203;</i><![endif]--></a>
      </div>
    </div>
    <!-- Fin: Sección ${idx+2} -->`;
    }).join('')}

    ${pagosHtml}
    ${clubHtml}

    ${socialTds?spacerImg:''}

    <!-- Redes -->
    ${socialTds?`<div style="display:table;width:100%;text-align:center;">
      <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto;width:auto;">
        <tr>${socialTds}</tr>
      </table>
    </div>`:''}

    ${footerUrlHtml}

  </div>

  ${disclaimerHtml}

</div>

</body>
</html>`;
}

function xe(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
// Logo de la plataforma; sin logoUrl (plataforma de ejemplo) va el nombre en texto
function mmLogo(c, color, imgHtml){
  return c.logoUrl ? imgHtml : `<span style="font-family:'Roboto Condensed',Arial,sans-serif;font-size:22px;font-weight:800;color:${color};display:inline-block;margin:${c.logoMargin};">${eh(c.name)}</span>`;
}
function eh(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

// ════════════════════════════════════════════════════════════
// DESCARGA / COPIA
// ════════════════════════════════════════════════════════════
function logExport(action){
  const v=V();
  const h1text=(document.getElementById('inpH1')?.value||'').trim();
  const mailingName=v.c.name+(h1text?' — '+h1text:'');
  fetch('log_mailing.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({platform:v.c.name, h1:v.h1, name:mailingName, html:gen()})
  }).catch(()=>{});
  saveHistory(action||'download');
}

function downloadHTML(){
  const html=gen();
  const blob=new Blob([html],{type:'text/html;charset=utf-8'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  const plat=PC[curPlat].name.replace(/\s+/g,'_').toLowerCase();
  const h1s=(document.getElementById('inpH1').value||'mailing').substring(0,28).replace(/[^a-zA-Z0-9áéíóúñ]/g,'_');
  a.href=url;a.download=`mailing_${plat}_${h1s}.html`;a.click();
  URL.revokeObjectURL(url);
  logExport('download');
}
async function copyHTML(){
  const el=document.getElementById('cpSt');
  try{
    await navigator.clipboard.writeText(gen());
    el.style.color='var(--green)';el.textContent='✓ HTML copiado al portapapeles';
    logExport('copy');
  }catch(e){
    el.style.color='var(--red)';el.textContent='No se pudo copiar — usá Descargar HTML';
  }
  setTimeout(()=>el.textContent='',3000);
}

// ════════════════════════════════════════════════════════════
// PLANTILLAS
// ════════════════════════════════════════════════════════════
function getTpls(){try{return JSON.parse(localStorage.getItem('ml_tpls_v2')||'[]');}catch{return[];}}
function setTpls(a){localStorage.setItem('ml_tpls_v2',JSON.stringify(a));}

function openTplModal(tab){
  document.getElementById('tplModal').classList.add('open');
  switchTplTab(tab||'saved');
}
function closeTplModal(){document.getElementById('tplModal').classList.remove('open');}
document.getElementById('tplModal').addEventListener('click',function(e){if(e.target===this)closeTplModal();});

function switchTplTab(tab){
  ['saved','team','save'].forEach(t=>{
    document.getElementById('ttab-'+t).classList.toggle('active',t===tab);
    document.getElementById('tpanel-'+t).classList.toggle('active',t===tab);
  });
  if(tab==='saved')renderTpls();
  if(tab==='team')renderTeamTpls();
}

// ── Plantillas del equipo (servidor) ──
let teamTpls=[];
async function renderTeamTpls(){
  const grid=document.getElementById('tplTeamGrid'), empty=document.getElementById('tplTeamEmpty');
  grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--text3);font-size:12px">Cargando…</div>'; empty.style.display='none';
  try{ const r=await fetch('templates.php'); const d=await r.json(); teamTpls=d.templates||[]; }catch(e){ teamTpls=[]; }
  if(!teamTpls.length){grid.innerHTML='';empty.style.display='block';return;}
  grid.innerHTML=teamTpls.map((x,i)=>{ const t=x.tpl||{}; return `
    <div class="tpl-card" data-on-click="mmApplyTeamTpl" data-click-1="j:${i}">
      ${t.img?`<img class="tpl-thumb" src="${eh(t.img)}" data-on-error="mmTplThumbErr" data-error-1="this">`:`<div class="tpl-ph"><i class="fa-solid fa-envelope"></i></div>`}
      <div class="tpl-info">
        <div class="tpl-name">${eh(x.name)}</div>
        <div class="tpl-meta">${eh(PC[x.plat]?.short||x.plat)} · ${eh(x.author||'')} · ${eh(x.date||'')}</div>
      </div>
      ${x.mine?`<button class="tpl-del" title="Borrar" data-n="${eh(x.name)}" data-on-click="mmDelTeamTpl" data-click-1="j:${x.id}" data-click-2="this">✕</button>`:''}
    </div>`; }).join('');
}
async function delTeamTpl(id,name){
  if(!confirm(`¿Borrar "${name}" para todo el equipo?`))return;
  const r=await fetch('templates.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})});
  const d=await r.json(); if(d.error){alert(d.error);return;} renderTeamTpls();
}
function renderTpls(){
  const tpls=getTpls();
  const grid=document.getElementById('tplGrid');
  const empty=document.getElementById('tplEmpty');
  if(!tpls.length){grid.innerHTML='';empty.style.display='block';return;}
  empty.style.display='none';
  grid.innerHTML=tpls.map((t,i)=>`
    <div class="tpl-card" data-on-click="applyTpl" data-click-1="j:${i}">
      ${t.img?`<img class="tpl-thumb" src="${t.img}" data-on-error="mmTplThumbErr" data-error-1="this">`:`<div class="tpl-ph"><i class="fa-solid fa-envelope"></i></div>`}
      <div class="tpl-info">
        <div class="tpl-name">${eh(t.name)}</div>
        <div class="tpl-meta">${eh(PC[t.plat]?.short||t.plat)} · ${t.date||''}</div>
      </div>
      <button class="tpl-del" data-on-click="delTpl" data-click-1="j:${i}">✕</button>
    </div>`).join('');
}
function saveTpl(){
  const name=document.getElementById('tplName').value.trim();
  const st=document.getElementById('tplSt');
  if(!name){st.style.color='var(--red)';st.textContent='⚠ Ingresá un nombre.';return;}
  const tpls=getTpls();
  if(tpls.length>=20 && !document.getElementById('tplShare')?.checked){st.style.color='var(--red)';st.textContent='⚠ Máx. 20 plantillas en este navegador. Borrá alguna o compartila con el equipo.';return;}
  const tpl={
    name,plat:curPlat,date:new Date().toLocaleDateString('es-AR'),
    img:imgUrl||'',imgExp:imgExport||'',
    h1:G('inpH1'),greeting:G('inpGreeting'),
    body1:document.getElementById('ed1').innerHTML,
    body2:document.getElementById('ed2').innerHTML,
    tnc:G('inpTnc'),ctaText:G('inpCtaText'),ctaUrl:G('inpCtaUrl'),
    linkMain:G('linkMain'),linkClub:G('linkClub'),linkPago:G('linkPago'),
    linkIG:G('linkIG'),linkFB:G('linkFB'),linkX:G('linkX'),
    linkWA:G('linkWA'),linkTT:G('linkTT'),linkYT:G('linkYT'),linkTG:G('linkTG'),
    extraSecs: extraSecs.map(s=>({...s})),
    bonusCards: {...bonusCards, cards:bonusCards.cards.map(c=>({...c}))},
  };
  if(document.getElementById('tplShare')?.checked){
    fetch('templates.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',name,plat:curPlat,data:tpl})})
      .then(r=>r.json()).then(d=>{
        if(d.error){st.style.color='var(--red)';st.textContent='⚠ '+d.error;return;}
        st.style.color='var(--green)';st.textContent='✓ Compartida con el equipo.';
        document.getElementById('tplName').value='';document.getElementById('tplShare').checked=false;
        setTimeout(()=>switchTplTab('team'),700);
      }).catch(()=>{st.style.color='var(--red)';st.textContent='⚠ No se pudo guardar. Probá de nuevo.';});
    return;
  }
  try{
    tpls.unshift(tpl);setTpls(tpls);
    st.style.color='var(--green)';st.textContent='✓ Plantilla guardada.';
    document.getElementById('tplName').value='';
    setTimeout(()=>switchTplTab('saved'),700);
  }catch(e){
    st.style.color='var(--red)';
    st.textContent=e.name==='QuotaExceededError'?'⚠ Sin espacio — eliminá plantillas antiguas.':'⚠ Error.';
  }
}
function applyTpl(i){ const t=getTpls()[i]; if(t) applyTplObj(t); }
function applyTplObj(t){
  if(!t)return;
  curPlat=MMP.key(t.plat);   // plantilla de una plataforma que este sitio ya no tiene: queda la de por defecto
  document.querySelectorAll('.plat-card').forEach(c=>c.classList.toggle('active', c.dataset.plat===curPlat));
  loadPlat(curPlat);
  document.getElementById('inpH1').value       =t.h1||'';
  document.getElementById('inpGreeting').value  =t.greeting||'';
  document.getElementById('ed1').innerHTML      =t.body1||'';
  document.getElementById('ed2').innerHTML      =t.body2||'';
  document.getElementById('inpTnc').value       =t.tnc||'';
  document.getElementById('inpCtaText').value   =t.ctaText||'';
  document.getElementById('inpCtaUrl').value    =t.ctaUrl||'';
  document.getElementById('linkMain').value     =t.linkMain||'';
  document.getElementById('linkClub').value     =t.linkClub||'';
  document.getElementById('linkPago').value     =t.linkPago||'';
  document.getElementById('linkIG').value       =t.linkIG||'';
  document.getElementById('linkFB').value       =t.linkFB||'';
  document.getElementById('linkX').value        =t.linkX||'';
  document.getElementById('linkWA').value       =t.linkWA||'';
  document.getElementById('linkTT').value       =t.linkTT||'';
  document.getElementById('linkYT').value       =t.linkYT||'';
  document.getElementById('linkTG').value       =t.linkTG||'';
  if(t.img){
    setImgUrl(t.img, t.imgExp||t.img);
  }
  extraSecs = Array.isArray(t.extraSecs) ? t.extraSecs.map(s=>({...s})) : [];
  renderExtraSecs();
  if(t.bonusCards){bonusCards={...t.bonusCards,cards:(t.bonusCards.cards||[]).map(c=>({...c}))};renderBonusCardsUI();}
  closeTplModal();update();
}
function delTpl(i){
  const tpls=getTpls();
  if(!confirm(`¿Eliminar "${tpls[i].name}"?`))return;
  tpls.splice(i,1);setTpls(tpls);renderTpls();
}

// ════════════════════════════════════════════════════════════
// CARDS DE BONOS
// ════════════════════════════════════════════════════════════
let bonusCards = {
  enabled: false,
  cols: 2,
  cards: [
    {title:'BONO #1', amount:'$500',     desc:'100% en tu primer depósito', color:'#ff6600'},
    {title:'BONO #2', amount:'50 Giros', desc:'Giros gratis en tu juego favorito', color:'#27cc2a'},
    {title:'BONO #3', amount:'$200',     desc:'Bono recarga semanal', color:'#c9a84c'},
  ]
};
const BC_COLORS = ['#ff6600','#27cc2a','#c9a84c','#60a5fa','#a78bfa','#f472b6','#fb923c','#34d399'];

init();

function toggleBonusCards(){
  bonusCards.enabled = !bonusCards.enabled;
  renderBonusCardsUI();
  update();
}

function setBonusCols(n){
  bonusCards.cols = n;
  document.querySelectorAll('.bc-col-btn').forEach(b => b.classList.toggle('active', +b.dataset.n === n));
  renderBonusCardsUI();
  update();
}

function updateBonusCard(idx, field, val){
  if(bonusCards.cards[idx]) bonusCards.cards[idx][field] = val;
  update();
}

function cycleBonusCardColor(idx){
  if(!bonusCards.cards[idx]) return;
  const i = BC_COLORS.indexOf(bonusCards.cards[idx].color);
  bonusCards.cards[idx].color = BC_COLORS[(i+1) % BC_COLORS.length];
  renderBonusCardsUI();
  update();
}

function renderBonusCardsUI(){
  const sw = document.getElementById('bcToggleSw');
  const wrap = document.getElementById('bcWrap');
  const editors = document.getElementById('bcCardEditors');
  if(sw) sw.classList.toggle('on', bonusCards.enabled);
  if(!wrap) return;
  wrap.style.display = bonusCards.enabled ? 'block' : 'none';
  if(!editors) return;
  editors.innerHTML = bonusCards.cards.slice(0, bonusCards.cols).map((card, i) => `
    <div class="bonus-card-item" style="border-top:2px solid ${card.color};">
      <div class="bc-row" style="align-items:center;margin-bottom:6px;">
        <button class="bc-color-btn" data-on-click="cycleBonusCardColor" data-click-1="j:${i}" title="Cambiar color"
          style="background:${card.color};"></button>
        <input class="bc-input" type="text" value="${eh(card.title)}" placeholder="BONO #${i+1} · Etiqueta"
          data-on-input="updateBonusCard" data-input-1="j:${i}" data-input-2="s:title" data-input-3="value"
          style="font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">
      </div>
      <div class="bc-row">
        <input class="bc-input amount" type="text" value="${eh(card.amount)}" placeholder="$500 / 50 Giros / 100%"
          data-on-input="updateBonusCard" data-input-1="j:${i}" data-input-2="s:amount" data-input-3="value">
      </div>
      <div class="bc-row">
        <input class="bc-input" type="text" value="${eh(card.desc)}" placeholder="Descripción corta"
          data-on-input="updateBonusCard" data-input-1="j:${i}" data-input-2="s:desc" data-input-3="value" style="color:var(--text2);">
      </div>
    </div>`).join('');
}

function genBonusCardsPreview(){
  if(!bonusCards.enabled) return '';
  const cols = bonusCards.cols;
  const cards = bonusCards.cards.slice(0, cols);
  const w = cols === 2 ? '50%' : '33.33%';
  const tds = cards.map((card, i) => {
    const pad = i === 0 ? '0 3px 0 0' : i === cols-1 ? '0 0 0 3px' : '0 3px';
    return `<td width="${w}" style="padding:${pad};vertical-align:top;">
      <div style="background:#1c1c1c;border-radius:8px;border-top:3px solid ${card.color};padding:14px 10px;text-align:center;">
        <div style="font-family:'Roboto Condensed',Arial,sans-serif;font-size:9px;font-weight:700;letter-spacing:2px;color:${card.color};text-transform:uppercase;margin-bottom:8px;">${xe(card.title)}</div>
        <div style="font-family:'Roboto Condensed',Arial,sans-serif;font-size:26px;font-weight:900;color:#fff;line-height:1;margin-bottom:6px;">${xe(card.amount)}</div>
        <div style="font-family:'Roboto Condensed',Arial,sans-serif;font-size:11px;color:#aaa;">${xe(card.desc)}</div>
      </div>
    </td>`;
  }).join('');
  return `<div style="margin:16px 0 0;"><table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>${tds}</tr></table></div>`;
}

function genBonusCardsExport(){
  if(!bonusCards.enabled) return '';
  const cols = bonusCards.cols;
  const cards = bonusCards.cards.slice(0, cols);
  const w = cols === 2 ? '50%' : '33.33%';
  const tds = cards.map((card, i) => {
    const pad = i === 0 ? '0 4px 0 0' : i === cols-1 ? '0 0 0 4px' : '0 4px';
    return `<td width="${w}" style="padding:${pad};vertical-align:top;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#1c1c1c;border-radius:8px;border-top:3px solid ${card.color};">
        <tr><td align="center" style="padding:14px 10px;">
          <p style="font-family:'Roboto Condensed',Arial,sans-serif;font-size:9px;font-weight:700;letter-spacing:2px;color:${card.color};text-transform:uppercase;margin:0 0 8px 0;">${xe(card.title)}</p>
          <p style="font-family:'Roboto Condensed',Arial,sans-serif;font-size:26px;font-weight:900;color:#ffffff;line-height:1;margin:0 0 6px 0;">${xe(card.amount)}</p>
          <p style="font-family:'Roboto Condensed',Arial,sans-serif;font-size:11px;color:#aaaaaa;margin:0;">${xe(card.desc)}</p>
        </td></tr>
      </table>
    </td>`;
  }).join('');
  return `\n    <div style="display:table;width:100%;margin:16px 0 0;">\n      <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>${tds}</tr></table>\n    </div>`;
}

// ════════════════════════════════════════════════════════════
// PREVIEW MOBILE / DESKTOP TOGGLE
// ════════════════════════════════════════════════════════════
let prevMode = 'desktop';
function togglePrevMode(){
  prevMode = prevMode === 'desktop' ? 'mobile' : 'desktop';
  const prev = document.getElementById('mlPrev');
  const btn  = document.getElementById('prevModeBtn');
  if(prevMode === 'mobile'){
    prev.style.maxWidth = '375px';
    btn.innerHTML = '<i class="fa-solid fa-desktop"></i>';
    btn.title = 'Vista desktop (480px)';
    btn.style.borderColor = 'rgba(255,102,0,0.4)';
    btn.style.color = 'var(--brand)';
  } else {
    prev.style.maxWidth = '480px';
    btn.innerHTML = '<i class="fa-solid fa-mobile-screen"></i>';
    btn.title = 'Vista mobile (375px)';
    btn.style.borderColor = '';
    btn.style.color = '';
  }
}

// ════════════════════════════════════════════════════════════
// HISTORIAL DE GENERADOS
// ════════════════════════════════════════════════════════════
function getFullState(){
  return {
    plat:curPlat,
    h1:G('inpH1'), greeting:G('inpGreeting'),
    body1:document.getElementById('ed1').innerHTML,
    body2:document.getElementById('ed2').innerHTML,
    tnc:G('inpTnc'), ctaText:G('inpCtaText'), ctaUrl:G('inpCtaUrl'),
    imgUrl:imgUrl, imgExp:imgExport,
    chkSameLink:document.getElementById('chkSameLink').checked,
    imgUrl2:G('inpImgUrl2')||'',
    linkMain:G('linkMain'),linkClub:G('linkClub'),linkPago:G('linkPago'),
    linkIG:G('linkIG'),linkFB:G('linkFB'),linkX:G('linkX'),
    linkWA:G('linkWA'),linkTT:G('linkTT'),linkYT:G('linkYT'),linkTG:G('linkTG'),
    extraSecs:extraSecs.map(s=>({...s})),
    bonusCards:{...bonusCards, cards:bonusCards.cards.map(c=>({...c}))},
  };
}

function restoreState(state){
  if(!state) return;
  curPlat = MMP.key(state.plat);
  document.querySelectorAll('.plat-card').forEach(c=>
    c.classList.toggle('active', c.dataset.plat === curPlat));
  loadPlat(curPlat);
  document.getElementById('inpH1').value       = state.h1||'';
  document.getElementById('inpGreeting').value = state.greeting||'';
  document.getElementById('ed1').innerHTML     = state.body1||'';
  document.getElementById('ed2').innerHTML     = state.body2||'';
  document.getElementById('inpTnc').value      = state.tnc||'';
  document.getElementById('inpCtaText').value  = state.ctaText||'';
  document.getElementById('inpCtaUrl').value   = state.ctaUrl||'';
  document.getElementById('linkMain').value    = state.linkMain||'';
  document.getElementById('linkClub').value    = state.linkClub||'';
  document.getElementById('linkPago').value    = state.linkPago||'';
  document.getElementById('linkIG').value      = state.linkIG||'';
  document.getElementById('linkFB').value      = state.linkFB||'';
  document.getElementById('linkX').value       = state.linkX||'';
  document.getElementById('linkWA').value      = state.linkWA||'';
  document.getElementById('linkTT').value      = state.linkTT||'';
  document.getElementById('linkYT').value      = state.linkYT||'';
  document.getElementById('linkTG').value      = state.linkTG||'';
  if(state.imgUrl) setImgUrl(state.imgUrl, state.imgExp||state.imgUrl);
  extraSecs = Array.isArray(state.extraSecs) ? state.extraSecs.map(s=>({...s})) : [];
  renderExtraSecs();
  if(state.bonusCards){
    bonusCards = {...state.bonusCards, cards:(state.bonusCards.cards||[]).map(c=>({...c}))};
    renderBonusCardsUI();
  }
  const chk = document.getElementById('chkSameLink');
  chk.checked = state.chkSameLink !== false;
  toggleImgLink();
  update();
  closeHistModal();
}

async function saveHistory(action){
  const v = V();
  const state = getFullState();
  try{
    await fetch('save_history.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        platform:curPlat, plat_name:v.c.name,
        h1:v.h1, action:action,
        state_json:JSON.stringify(state)
      })
    });
  }catch(e){}
}

function openHistModal(){
  document.getElementById('histModal').classList.add('open');
  loadHistModal();
}
function closeHistModal(){
  document.getElementById('histModal').classList.remove('open');
}
// El modal está más abajo en la página: se engancha cuando termina de cargar (antes cortaba este script)
document.addEventListener('DOMContentLoaded',()=>document.getElementById('histModal')?.addEventListener('click',function(e){
  if(e.target===this) closeHistModal();
}));

async function loadHistModal(){
  const grid  = document.getElementById('histGrid');
  const empty = document.getElementById('histEmpty');
  grid.innerHTML = '<div class="hist-empty">Cargando...</div>';
  empty.style.display = 'none';
  try{
    const r = await fetch('get_history.php');
    const rows = await r.json();
    if(!rows || !rows.length){
      grid.innerHTML = '';
      empty.style.display = 'block';
      return;
    }
    grid.innerHTML = rows.map((row) => {
      const timeAgo  = fmtTimeAgo(row.created_at);
      const icon     = row.action === 'copy' ? 'fa-copy' : 'fa-download';
      const stateObj = row.state_json ? JSON.parse(row.state_json) : null;
      const stateStr = stateObj ? eh(JSON.stringify(stateObj)) : 'null';   // va en un atributo data-click-1: escapado entero (antes las comillas dobles cortaban el onclick)
      return `<div class="hist-item">
        <span class="hist-plat">${eh(row.plat_name||row.platform)}</span>
        <div class="hist-info">
          <div class="hist-h1">${eh(row.h1||'(sin título)')}</div>
          <div class="hist-meta">
            <i class="fa-solid ${icon}" style="font-size:9px;margin-right:4px;"></i>${eh(timeAgo)}
            ${row.user_name ? ' · '+eh(row.user_name) : ''}
          </div>
        </div>
        <button class="hist-restore" data-on-click="restoreStateFromStr" data-click-1="s:${stateStr}">
          <i class="fa-solid fa-rotate-left" style="margin-right:4px;"></i>Restaurar
        </button>
      </div>`;
    }).join('');
  }catch(e){
    grid.innerHTML = '<div class="hist-empty">Error cargando historial.</div>';
  }
}

function restoreStateFromStr(str){
  try{ restoreState(JSON.parse(str.replace(/&#39;/g,"'")));}catch(e){alert('Error al restaurar.');}
}

function fmtTimeAgo(dateStr){
  if(!dateStr) return '';
  try{
    const d = new Date(dateStr.replace(' ','T')+'Z');
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if(diff < 90) return 'hace un momento';
    if(diff < 3600) return 'hace '+Math.floor(diff/60)+' min';
    if(diff < 86400) return 'hace '+Math.floor(diff/3600)+' h';
    return 'hace '+Math.floor(diff/86400)+' días';
  }catch(e){ return dateStr; }
}


// ════════════════════════════════════════════════════════════
// CHECKBOX LINK IMAGEN / CTA
// ════════════════════════════════════════════════════════════
function toggleImgLink(){
  const shared = document.getElementById('chkSameLink').checked;
  document.getElementById('imgLinkWrap').style.display = shared ? 'none' : 'block';
  update();
}

// Devuelve el link que se usa para la imagen en el mailing
function getImgLink(){
  const shared = document.getElementById('chkSameLink').checked;
  if(shared) return G('inpCtaUrl');
  return G('inpImgUrl2')||G('inpCtaUrl');
}

// ════════════════════════════════════════════════════════════
// GENERADOR DE LINKS — MODAL
// ════════════════════════════════════════════════════════════
let _linkGenTarget = ''; // id del input que recibirá el link generado

// Los proyectos (dominios) del generador salen de MMP.lgProjects (botones armados en mmBuildPlatUI)
const LG_ACTIONS = [
  {path:'/registration/register', label:'Registro'},
  {path:'/referafriend/invite',   label:'Referidos'},
  {path:'/?profile=1',            label:'Perfil'},
  {path:'/?deposit=1',            label:'Depósito'},
];

let lgProject = '';
let lgAction  = '';

function openLinkGen(targetInputId){
  _linkGenTarget = targetInputId;
  lgProject = ''; lgAction = '';
  // Pre-seleccionar proyecto según plataforma activa
  lgProject = MMP.lgDomains[curPlat]||'';
  renderLinkGen();
  document.getElementById('linkGenModal').classList.add('open');
}
function closeLinkGen(){
  document.getElementById('linkGenModal').classList.remove('open');
  document.getElementById('lgGameWrap').style.display='none';
  document.getElementById('lgGameInput').value='';
}
document.addEventListener('DOMContentLoaded',()=>document.getElementById('linkGenModal')?.addEventListener('click',function(e){if(e.target===this)closeLinkGen();}));

function renderLinkGen(){
  // Proyectos
  document.querySelectorAll('.lg-proj-btn').forEach(b=>{
    b.classList.toggle('lg-active', b.dataset.domain===lgProject);
  });
  // Acciones
  document.querySelectorAll('.lg-act-btn').forEach(b=>{
    b.classList.toggle('lg-active', b.dataset.path===lgAction);
  });
  // Preview URL
  const prev = document.getElementById('lgUrlPreview');
  if(lgProject&&lgAction){
    prev.textContent='https://'+lgProject+lgAction;
    prev.style.color='var(--text)';
  } else {
    prev.textContent='Seleccioná proyecto y acción...';
    prev.style.color='var(--text3)';
  }
  document.getElementById('btnLgApply').disabled=!(lgProject&&lgAction);
}

function lgSetProject(domain){lgProject=domain;renderLinkGen();}
function lgSetAction(path){
  lgAction=path;
  document.getElementById('lgGameWrap').style.display='none';
  renderLinkGen();
}
function lgOpenGame(){
  document.getElementById('lgGameWrap').style.display='block';
  document.getElementById('lgGameInput').focus();
  document.querySelectorAll('.lg-act-btn').forEach(b=>b.classList.remove('lg-active'));
}
function lgProcessGame(){
  const raw=document.getElementById('lgGameInput').value.trim();
  if(!raw)return;
  try{
    const u=new URL(raw.startsWith('http')?raw:'https://'+raw);
    // ? & = # de la ruta del juego cortarían el parámetro gameurl: se codifican (una ruta sin parámetros queda igual)
    lgAction='/?logdialog=1&gameurl='+(u.pathname+u.search).replace(/[?&=#]/g,c=>encodeURIComponent(c));
  }catch(e){
    lgAction='/?logdialog=1&gameurl='+raw;
  }
  // Marcar botón juego como activo
  document.querySelectorAll('.lg-act-btn').forEach(b=>{
    b.classList.toggle('lg-active', b.dataset.path==='__game__');
  });
  renderLinkGen();
}
function lgApply(){
  if(!lgProject||!lgAction)return;
  const url='https://'+lgProject+lgAction;
  const inp=document.getElementById(_linkGenTarget);
  if(inp){inp.value=url;inp.dispatchEvent(new Event('input'));}
  closeLinkGen();
}

// ── Eventos que antes estaban en línea (assets/g7-on.js los llama por nombre) ──
function mmHideImg(img){ img.style.display='none'; }
function mmSwapHtml(el, html){ el.outerHTML = html; }   // logo que no carga: se reemplaza por el nombre de la plataforma
function mmGalImgErr(img){ img.style.display='none'; img.parentElement.innerHTML='<div style="color:#999;font-size:11px;">Sin imagen</div>'; }
function mmTplThumbErr(img){ img.outerHTML='<div class=tpl-ph><i class=fa-solid fa-envelope></i></div>'; }
function mmPickImgFile(){ document.getElementById('imgFileInp').click(); }
function mmDragOver(e, el){ e.preventDefault(); el.classList.add('over'); }
function mmDragLeave(el){ el.classList.remove('over'); }
function mmApplyTeamTpl(i){ applyTplObj(teamTpls[i].tpl); }
function mmDelTeamTpl(id, el){ return delTeamTpl(id, el.dataset.n); }
function mmOpenTplModal(){ openTplModal(); return false; }   // enlace "Plantillas" que index.php agrega a la barra
