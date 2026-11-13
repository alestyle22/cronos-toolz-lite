/* assets/tools/webpmaker.js — código de webpmaker.html (sin código en línea: CSP estricta) */
const inputFile = document.getElementById('imageFile');
const convertBtn = document.getElementById('convertBtn');
const output = document.getElementById('output');
const qualityInput = document.getElementById('quality');
const maxSizeInput = document.getElementById('maxSize');

async function compressImageToWebP(img, initialQuality, maxSizeKB){
  let quality = initialQuality/100;
  let blob;
  for(let i=0;i<10;i++){ // máximo 10 iteraciones
    blob = await new Promise(res => imgToWebP(img, quality, res));
    const sizeKB = blob.size/1024;
    if(sizeKB <= maxSizeKB || quality <= 0.3) break;
    quality -= 0.1; // reduce calidad 10% cada iteración
    if(quality < 0.3) quality = 0.3;
  }
  return blob;
}

function imgToWebP(img, quality, callback){
  const canvas = document.createElement('canvas');
  canvas.width = img.width;
  canvas.height = img.height;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(img,0,0);
  canvas.toBlob(callback, 'image/webp', quality);
}

convertBtn.addEventListener('click', ()=>{
  const files = Array.from(inputFile.files);
  if(!files.length){ alert('Sube al menos una imagen PNG o JPG'); return; }

  output.innerHTML = '';
  const initialQuality = Math.min(100, Math.max(1, Number(qualityInput.value)));
  const maxSizeKB = Number(maxSizeInput.value);

  files.forEach(file => {
    const reader = new FileReader();
    reader.onload = function(e){
      const img = new Image();
      img.onload = async function(){
        const blob = await compressImageToWebP(img, initialQuality, maxSizeKB);
        const url = URL.createObjectURL(blob);

        const block = document.createElement('div');
        block.className='image-block';

        const a = document.createElement('a');
        a.href = url;
        a.download = file.name.replace(/\.(png|jpg|jpeg)$/i,'.webp');
        a.textContent = `Descargar WebP (${Math.round(blob.size/1024)} KB)`;
        a.style.display='block';
        a.style.marginBottom='0.5rem';

        const preview = document.createElement('img');
        preview.src = url;

        block.appendChild(preview);
        block.appendChild(a);
        output.appendChild(block);
      };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  });
});
