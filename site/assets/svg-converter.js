/* assets/svg-converter.js — Script de svg_converter.php (SVG → PNG), sin código en línea (CSP estricta, SEC-24). */
let svgFiles = []; // [{name, text, dataUrl}]
let bgColor = 'transparent';
let allBlobs = {}; // {filename_variantKey_size: Blob}

const VARIANT_CONFIG = {
  positive: { label:'Original',  badge:'POSITIVO', badgeStyle:'background:#f5f2eb;color:#1a1814;border-color:#c8c2b5', filter:null },
  negative: { label:'Negativo',  badge:'NEGATIVO', badgeStyle:'background:#1a1814;color:#f5f2eb;border-color:#1a1814', filter:'invert(1)' },
  black:    { label:'Monocromático Negro', badge:'NEGRO', badgeStyle:'background:#000;color:#fff;border-color:#000', filter:'brightness(0)' },
  white:    { label:'Monocromático Blanco', badge:'BLANCO', badgeStyle:'background:#fff;color:#000;border-color:#ccc', filter:'brightness(0) invert(1)' },
};

// ── FILE DROP ──────────────────────────────────────────────
// ── Antes código en línea (CSP estricta, SEC-24) ──
function svAddMore(el) { addMoreFiles(el.files); }
function svHide(el) { el.style.display = 'none'; }

function ev(e, type) {
  e.preventDefault();
  const drop = document.getElementById('drop');
  if (type === 'over')  drop.classList.add('over');
  if (type === 'leave') drop.classList.remove('over');
  if (type === 'drop')  { drop.classList.remove('over'); loadFiles(e.dataTransfer.files); }
}
document.getElementById('file-in').addEventListener('change', e => loadFiles(e.target.files));

async function loadFiles(files) {
  const svgs = [...files].filter(f => f.type==='image/svg+xml'||f.name.endsWith('.svg'));
  if (!svgs.length) { toast('Solo se aceptan archivos SVG'); return; }
  svgFiles = [];
  for (const f of svgs) {
    const text = await f.text();
    const dataUrl = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(text)));
    svgFiles.push({name: f.name, text, dataUrl, size: f.size});
  }
  renderFileList();
  document.getElementById('drop').style.display = 'none';
  document.getElementById('gen-btn').disabled = false;
}

async function addMoreFiles(files) {
  const svgs = [...files].filter(f => f.type==='image/svg+xml'||f.name.endsWith('.svg'));
  for (const f of svgs) {
    if (svgFiles.find(x=>x.name===f.name)) continue; // skip dups
    const text = await f.text();
    const dataUrl = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(text)));
    svgFiles.push({name: f.name, text, dataUrl, size: f.size});
  }
  renderFileList();
  toast(`${svgFiles.length} archivo${svgFiles.length>1?'s':''} cargado${svgFiles.length>1?'s':''}`);
}

function renderFileList() {
  const list = document.getElementById('file-list');
  const actions = document.getElementById('file-actions');
  list.style.display = svgFiles.length ? 'flex' : 'none';
  actions.style.display = svgFiles.length ? 'flex' : 'none';
  list.innerHTML = svgFiles.map((f,i) => `
    <div class="sv-fl-item">
      <img class="sv-fl-prev" src="${f.dataUrl}" data-on-error="svHide" data-error-1="this">
      <span class="sv-fl-name">${f.name}</span>
      <span class="sv-fl-size">${fmtBytes(f.size)}</span>
      <button class="sv-fl-rm" data-on-click="removeFile" data-click-1="j:${i}" title="Quitar">✕</button>
    </div>`).join('');
}

function removeFile(i) {
  svgFiles.splice(i, 1);
  renderFileList();
  if (!svgFiles.length) clearAll();
}

function clearAll() {
  svgFiles=[]; allBlobs={};
  document.getElementById('file-list').style.display='none';
  document.getElementById('file-list').innerHTML='';
  document.getElementById('file-actions').style.display='none';
  document.getElementById('drop').style.display='';
  document.getElementById('gen-btn').disabled=true;
  document.getElementById('results').classList.remove('show');
  document.getElementById('empty-state').style.display='';
  document.getElementById('file-in').value='';
}

// ── TOGGLES ──────────────────────────────────────────────
function toggleSize(btn)  { btn.classList.toggle('on'); }
function toggleVar(btn)   { btn.classList.toggle('on'); }
function setBg(c, el) {
  bgColor = c;
  document.querySelectorAll('.sv-bg-sw').forEach(s=>s.classList.remove('on'));
  el?.classList.add('on');
  const lbls = {'transparent':'Transparente','#ffffff':'Blanco','#000000':'Negro','#f5f2eb':'Crema'};
  document.getElementById('bg-lbl').textContent = lbls[c]||c;
}
function addCustomSize() {
  const px = parseInt(document.getElementById('custom-px').value);
  if (!px||px<1||px>4096) { toast('Tamaño inválido (1-4096px)'); return; }
  const grid = document.getElementById('size-grid');
  if ([...grid.querySelectorAll('[data-px]')].some(b=>b.dataset.px==px)) { toast('Ese tamaño ya existe'); return; }
  const btn = document.createElement('button');
  btn.className='sv-size-btn on'; btn.dataset.px=px; btn.onclick=()=>toggleSize(btn);
  btn.innerHTML=`<span class="px">${px}px</span><span class="use">Custom</span>`;
  grid.appendChild(btn);
  document.getElementById('custom-px').value='';
}

// ── GENERATE ──────────────────────────────────────────────
async function generate() {
  if (!svgFiles.length) return;

  const sizes   = [...document.querySelectorAll('#size-grid .sv-size-btn.on')].map(b=>parseInt(b.dataset.px));
  const variants= [...document.querySelectorAll('.sv-var-btn.on')].map(b=>b.dataset.v);

  if (!sizes.length)   { toast('Selecciona al menos un tamaño'); return; }
  if (!variants.length){ toast('Selecciona al menos una variante'); return; }

  const btn = document.getElementById('gen-btn');
  btn.disabled=true;

  allBlobs = {};
  const container = document.getElementById('variants-container');
  container.innerHTML='';
  const dlRow = document.getElementById('dl-row');
  dlRow.innerHTML='';

  document.getElementById('empty-state').style.display='none';
  document.getElementById('results').classList.add('show');

  const totalTasks = svgFiles.length * variants.length * sizes.length;
  let done = 0;

  for (const svgFile of svgFiles) {
    const fileSlug = svgFile.name.replace(/\.svg$/i,'');

    // File header
    if (svgFiles.length > 1) {
      const fHeader = document.createElement('div');
      fHeader.style.cssText='display:flex;align-items:center;gap:10px;padding:12px 0;margin-top:16px;border-top:3px solid var(--ink)';
      fHeader.innerHTML=`<img src="${svgFile.dataUrl}" style="width:28px;height:28px;object-fit:contain;border:1px solid var(--border)"><span style="font-family:'IBM Plex Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em">${svgFile.name}</span>`;
      container.appendChild(fHeader);
    }

    for (const variant of variants) {
      const cfg = VARIANT_CONFIG[variant];
      const section = document.createElement('div');
      section.className='sv-var-section';
      const sectionId = `cards-${fileSlug.replace(/[^a-z0-9]/gi,'_')}-${variant}`;
      section.innerHTML=`<div class="sv-var-title"><i class="fa-solid fa-circle-half-stroke"></i>${cfg.label}<span class="badge" style="${cfg.badgeStyle}">${cfg.badge}</span></div><div class="sv-cards" id="${sectionId}"></div>`;
      container.appendChild(section);

      for (const size of sizes.sort((a,b)=>a-b)) {
        const key = `${fileSlug}__${variant}__${size}`;
        try {
          const blob = await renderVariant(svgFile.text, variant, size);
          allBlobs[key] = blob;
          appendCardTo(sectionId, fileSlug, variant, size, blob, cfg);
        } catch(e) { console.error(e); }
        done++;
        btn.innerHTML=`<i class="fa-solid fa-spinner fa-spin"></i> ${done}/${totalTasks}`;
      }
    }
  }

  // Download all
  const dlAll = document.createElement('button');
  dlAll.className='sv-dl-btn';
  dlAll.innerHTML=`<i class="fa-solid fa-file-zipper"></i> Descargar ZIP (${Object.keys(allBlobs).length} PNG)`;
  dlAll.onclick=downloadAll;
  dlRow.appendChild(dlAll);

  btn.innerHTML='<i class="fa-solid fa-wand-magic-sparkles"></i> Regenerar';
  btn.disabled=false;
  toast(`✓ ${Object.keys(allBlobs).length} PNG generados — ${svgFiles.length} archivo${svgFiles.length>1?'s':''}`);
}

async function renderVariant(svg, variant, size) {
  // Parse original SVG
  const parser = new DOMParser();
  const doc = parser.parseFromString(svg, 'image/svg+xml');
  const svgEl = doc.querySelector('svg');
  if (!svgEl) throw new Error('Invalid SVG');

  // Get original viewBox / dims
  let vb = svgEl.getAttribute('viewBox');
  if (!vb) {
    const w = parseFloat(svgEl.getAttribute('width'))||100;
    const h = parseFloat(svgEl.getAttribute('height'))||100;
    vb = `0 0 ${w} ${h}`;
    svgEl.setAttribute('viewBox', vb);
  }

  // Apply color filter via CSS
  const filter = VARIANT_CONFIG[variant].filter;
  if (filter) {
    const style = doc.createElementNS('http://www.w3.org/2000/svg','style');
    style.textContent = `svg { filter: ${filter}; }`;
    svgEl.insertBefore(style, svgEl.firstChild);
  }

  // Set explicit size
  svgEl.setAttribute('width', size);
  svgEl.setAttribute('height', size);

  const finalSvg = new XMLSerializer().serializeToString(doc);
  const blob = new Blob([finalSvg], {type:'image/svg+xml'});
  const url  = URL.createObjectURL(blob);

  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = size;
      const ctx = canvas.getContext('2d');
      if (bgColor !== 'transparent') {
        ctx.fillStyle = bgColor;
        ctx.fillRect(0,0,size,size);
      }
      ctx.drawImage(img,0,0,size,size);
      URL.revokeObjectURL(url);
      canvas.toBlob(resolve,'image/png');
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Render failed')); };
    img.src = url;
  });
}

function appendCardTo(sectionId, fileSlug, variant, size, blob, cfg) {
  const cards = document.getElementById(sectionId);
  if (!cards) return;
  const url = URL.createObjectURL(blob);
  const prevSize = Math.min(size, 96);
  const key = `${fileSlug}__${variant}__${size}`;
  const card = document.createElement('div');
  card.className = 'sv-card';
  card.innerHTML=`
    <div class="sv-card-prev" style="width:${Math.max(prevSize+16,80)}px;height:${Math.max(prevSize+16,80)}px">
      <img src="${url}" width="${prevSize}" height="${prevSize}" style="image-rendering:pixelated">
    </div>
    <div class="sv-card-info">
      <span class="sv-card-size">${size}×${size}</span>
      <button class="sv-card-dl" data-on-click="dlKey" data-click-1="s:${key}" data-click-2="s:${fileSlug}" data-click-3="s:${variant}" data-click-4="s:${size}"><i class="fa-solid fa-download"></i> PNG</button>
    </div>`;
  cards.appendChild(card);
}

// ── DOWNLOADS ─────────────────────────────────────────────
function dlKey(key, fileSlug, variant, size) {
  const blob = allBlobs[key];
  if (!blob) return;
  const vLabel = VARIANT_CONFIG[variant]?.label.toLowerCase().replace(/\s+/g,'-')||variant;
  dl(blob, `${fileSlug}_${vLabel}_${size}x${size}.png`);
}

// downloadVariant removed — use downloadAll

async function downloadAll() {
  const keys = Object.keys(allBlobs);
  if (!keys.length) return;

  const dlAllBtn = document.querySelector('[onclick="downloadAll()"]');
  if (dlAllBtn) { dlAllBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Creando ZIP...'; dlAllBtn.disabled=true; }

  try {
    const zip = new JSZip();

    for (const key of keys) {
      const [fileSlug, variant, size] = key.split('__');
      const cfg = VARIANT_CONFIG[variant];
      const vLabel = cfg?.label || variant;
      const blob = allBlobs[key];
      if (!blob) continue;

      // Folder structure: SVGName / VariantName / size.png
      const folder = svgFiles.length > 1
        ? `${fileSlug}/${vLabel}/`
        : `${vLabel}/`;
      const filename = `${size}x${size}.png`;
      zip.file(folder + filename, blob);
    }

    const zipName = svgFiles.length === 1
      ? `${svgFiles[0].name.replace(/\.svg$/i,'')}_export.zip`
      : `svg_export_${Date.now()}.zip`;

    const zipBlob = await zip.generateAsync({type:'blob', compression:'DEFLATE', compressionOptions:{level:6}}, meta => {
      if (dlAllBtn) dlAllBtn.innerHTML=`<i class="fa-solid fa-spinner fa-spin"></i> ${Math.round(meta.percent)}%`;
    });

    dl(zipBlob, zipName);
    toast(`↓ ZIP listo — ${keys.length} PNG en ${zipName}`);
  } catch(e) {
    console.error(e);
    toast('Error al crear el ZIP');
  }

  if (dlAllBtn) { dlAllBtn.innerHTML='<i class="fa-solid fa-file-zipper"></i> Descargar ZIP'; dlAllBtn.disabled=false; }
}

function dl(blob, name) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.click();
  setTimeout(()=>URL.revokeObjectURL(a.href), 1000);
}

function fmtBytes(b){if(b<1024)return b+"B";if(b<1048576)return(b/1024).toFixed(1)+"KB";return(b/1048576).toFixed(1)+"MB";}
function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent=msg; t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), 2800);
}

document.addEventListener('keydown', e=>{
  if((e.ctrlKey||e.metaKey)&&e.key==='Enter'&&svgFiles.length) generate();
});
// dragleave no está entre los eventos de g7-on: se engancha directo en la zona de soltar
document.getElementById('drop').addEventListener('dragleave', e => ev(e, 'leave'));
