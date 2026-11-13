/* assets/tools/recolorearsvg.js — código de recolorearsvg.html (sin código en línea: CSP estricta) */
// Helper: traverse all nodes and set fill/stroke where appropriate
function recolorSvgDocument(svgDoc, color) {
  const elems = svgDoc.querySelectorAll('*');
  elems.forEach(el => {
    // skip if element explicitly has fill="none"
    const fillAttr = el.getAttribute('fill');
    if (fillAttr && fillAttr.trim().toLowerCase() === 'none') {
      // keep none
    } else {
      try {
        // set presentation attribute
        el.setAttribute('fill', color);
      } catch(e){}
    }

    const strokeAttr = el.getAttribute('stroke');
    if (strokeAttr && strokeAttr.trim().toLowerCase() === 'none') {
      // keep none
    } else {
      try {
        // if element originally had stroke, set it; otherwise leave it
        // to make simple logos consistently colored, we also set stroke
        el.setAttribute('stroke', color);
      } catch(e){}
    }

    // also try inline style override
    const style = el.getAttribute('style');
    if (style) {
      // remove any fill:... and stroke:... from style then append our value
      let s = style.replace(/fill\s*:\s*[^;]+;?/ig,'').replace(/stroke\s*:\s*[^;]+;?/ig,'');
      s = s.trim();
      if (s && !s.endsWith(';')) s += ';';
      s += `fill:${color};stroke:${color};`;
      el.setAttribute('style', s);
    }
  });

  // set root svg fallback
  const root = svgDoc.documentElement;
  root.setAttribute('fill', color);
  root.setAttribute('stroke', color);
}

function svgTextToDocument(svgText) {
  const parser = new DOMParser();
  const doc = parser.parseFromString(svgText, 'image/svg+xml');
  // handle parsererror
  if (doc.querySelector('parsererror')) throw new Error('SVG inválido o con errores');
  return doc;
}

function docToText(doc) {
  const serializer = new XMLSerializer();
  return serializer.serializeToString(doc.documentElement);
}

function getSvgSize(svgText) {
  // try to extract width/height or viewBox
  const tmp = svgText;
  const viewBoxMatch = tmp.match(/viewBox\s*=\s*"([^"]+)"/i);
  if (viewBoxMatch) {
    const parts = viewBoxMatch[1].trim().split(/\s+/).map(Number);
    if (parts.length === 4) {
      const w = parts[2];
      const h = parts[3];
      if (!isNaN(w) && !isNaN(h)) return {w: w, h: h};
    }
  }
  const widthMatch = tmp.match(/width\s*=\s*"(\d+(?:\.\d+)?)"/i);
  const heightMatch = tmp.match(/height\s*=\s*"(\d+(?:\.\d+)?)"/i);
  if (widthMatch && heightMatch) return {w: Number(widthMatch[1]), h: Number(heightMatch[1])};
  // fallback to 512 square
  return {w:512,h:512};
}

async function svgTextToPng(svgText, targetWidth){
  const size = getSvgSize(svgText);
  let w = size.w; let h = size.h;
  if (targetWidth && targetWidth > 0) {
    const ratio = size.h/size.w;
    w = targetWidth; h = Math.round(w*ratio);
  }
  const svgBlob = new Blob([svgText], {type:'image/svg+xml;charset=utf-8'});
  const url = URL.createObjectURL(svgBlob);

  const img = new Image();
  img.onload = () => {};
  img.onerror = (e) => { console.error('error cargando svg como imagen', e); };
  img.src = url;

  await new Promise((res, rej) => { img.onload = res; img.onerror = rej; });

  // draw to canvas
  const ratio = window.devicePixelRatio || 1;
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(w * ratio);
  canvas.height = Math.round(h * ratio);
  canvas.style.width = w + 'px';
  canvas.style.height = h + 'px';
  const ctx = canvas.getContext('2d');
  ctx.setTransform(ratio,0,0,ratio,0,0);
  // clear with transparent
  ctx.clearRect(0,0,w,h);
  ctx.drawImage(img,0,0,w,h);
  URL.revokeObjectURL(url);
  return new Promise((res) => {
    canvas.toBlob(blob => { res({blob,canvas}); }, 'image/png');
  });
}

// UI wiring
const inputFile = document.getElementById('svgFile');
const colorsInput = document.getElementById('colors');
const sizeInput = document.getElementById('size');
const generateBtn = document.getElementById('generate');
const status = document.getElementById('status');
const results = document.getElementById('results');
const downloadZipBtn = document.getElementById('downloadZip');
let latestBlobs = [];

inputFile.addEventListener('change', ()=>{
  const f = inputFile.files[0];
  if(!f) { document.getElementById('fileInfo').innerText=''; return; }
  document.getElementById('fileInfo').innerText = `Archivo: ${f.name} — ${Math.round(f.size/1024)} KB`;
});

generateBtn.addEventListener('click', async ()=>{
  results.innerHTML=''; latestBlobs = []; downloadZipBtn.disabled=true; status.innerText='';
  const f = inputFile.files[0];
  if(!f) { alert('Sube un archivo SVG primero'); return; }
  const colorsRaw = colorsInput.value.split(',').map(s=>s.trim()).filter(s=>s.length);
  if(colorsRaw.length===0){ alert('Indica al menos un color'); return; }
  status.innerText = 'Procesando...';

  const text = await f.text();

  for (let i=0;i<colorsRaw.length;i++){
    const color = colorsRaw[i];
    try{
      const doc = svgTextToDocument(text);
      // recolor doc
      recolorSvgDocument(doc, color);
      const outSvg = docToText(doc);
      const {blob, canvas} = await svgTextToPng(outSvg, Number(sizeInput.value) || 0);
      const url = URL.createObjectURL(blob);

      // create preview element
      const div = document.createElement('div');
      div.className='chip';
      const img = document.createElement('img');
      img.src = url;
      img.style.height = '64px';
      img.style.display = 'block';
      img.style.marginBottom = '6px';
      const a = document.createElement('a');
      a.href = url;
      a.download = `logo-${color.replace(/[^a-z0-9]/ig,'') || i}.png`;
      a.className = 'button';
      a.textContent = `Descargar ${color}`;

      div.appendChild(img);
      div.appendChild(a);

      results.appendChild(div);

      // save blob for zip
      latestBlobs.push({name: `logo-${color.replace(/[^a-z0-9]/ig,'') || i}.png`, blob});
    }catch(e){
      console.error('error procesando color', color, e);
      const err = document.createElement('div'); err.textContent = `Error con ${color}: ${e.message}`; results.appendChild(err);
    }
  }

  if(latestBlobs.length) downloadZipBtn.disabled=false;
  status.innerText = `Listo — ${latestBlobs.length} archivos generados.`;
});

downloadZipBtn.addEventListener('click', async ()=>{
  if(!latestBlobs.length) return;
  downloadZipBtn.disabled=true; downloadZipBtn.textContent='Creando ZIP...';
  const zip = new JSZip();
  latestBlobs.forEach(item => zip.file(item.name, item.blob));
  const content = await zip.generateAsync({type:'blob'});
  const url = URL.createObjectURL(content);
  const a = document.createElement('a'); a.href = url; a.download = 'logos-multicolor.zip'; document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
  downloadZipBtn.disabled=false; downloadZipBtn.textContent='Descargar ZIP';
});
