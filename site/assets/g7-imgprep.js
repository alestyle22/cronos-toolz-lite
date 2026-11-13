/**
 * g7PrepImage(file, opts) — achica una foto en el navegador antes de subirla.
 * El hosting de cronos acepta archivos de hasta 2 MB: una foto de celular (4–8 MB) llegaba vacía y se perdía sin aviso.
 * Si la imagen ya es chica y no pasa del lado máximo, se devuelve tal cual. GIF y SVG no se tocan (se perdería la animación o el vector).
 *   opts.max      lado mayor en px (default 2000)
 *   opts.maxBytes peso a partir del cual se recomprime (default 1,5 MB)
 */
(function () {
  const loadImg = file => new Promise((ok, bad) => {
    const url = URL.createObjectURL(file), img = new Image();
    img.onload = () => { URL.revokeObjectURL(url); ok(img); };
    img.onerror = () => { URL.revokeObjectURL(url); bad(new Error('No se pudo leer la imagen')); };
    img.src = url;
  });
  const toBlob = (canvas, type, q) => new Promise(ok => canvas.toBlob(ok, type, q));

  window.g7PrepImage = async function (file, opts = {}) {
    const max = opts.max || 2000, maxBytes = opts.maxBytes || 1.5 * 1024 * 1024;
    if (!file || !/^image\/(jpeg|png|webp|avif|bmp)$/i.test(file.type)) return file;
    let img;
    try { img = await loadImg(file); } catch (e) { return file; }
    const w = img.naturalWidth, h = img.naturalHeight, scale = Math.min(1, max / Math.max(w, h));
    if (scale === 1 && file.size <= maxBytes) return file;
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
    // WebP conserva la transparencia de los PNG y pesa poco; si el navegador no lo genera, JPG
    let blob = await toBlob(canvas, 'image/webp', 0.86), ext = 'webp';
    if (!blob || blob.type !== 'image/webp') { blob = await toBlob(canvas, 'image/jpeg', 0.86); ext = 'jpg'; }
    for (let q = 0.76; blob && blob.size > maxBytes && q > 0.4; q -= 0.12) blob = await toBlob(canvas, ext === 'webp' ? 'image/webp' : 'image/jpeg', q);
    if (!blob || blob.size >= file.size && scale === 1) return file;
    const base = (file.name || 'imagen').replace(/\.[^.]+$/, '');
    return new File([blob], base + '.' + ext, { type: blob.type, lastModified: Date.now() });
  };

  /** Mensaje legible para una respuesta de subida que vino mal (JSON con error, HTML de error del servidor o vacío). */
  window.g7UploadError = function (data, fallback) {
    return (data && (data.error || data.message)) || fallback || 'No se pudo subir la imagen. Prueba con una más liviana.';
  };
})();
