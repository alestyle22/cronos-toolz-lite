/* assets/tools/webpmaker2.js — código de webpmaker2.html (sin código en línea: CSP estricta) */
(function(){
  const $ = id => document.getElementById(id);
  const items = [];   // {id, file, blob, url, name, w, h, q, state, msg}
  let busy = false, seq = 0;
  const fmtKB = b => b >= 1048576 ? (b/1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b/1024)) + ' KB';
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  // Ajustes recordados
  try { const s = JSON.parse(localStorage.getItem('webp_cfg') || '{}'); if (s.q) $('quality').value = s.q; if (s.kb !== undefined) $('maxKB').value = s.kb; if (s.w !== undefined) $('maxW').value = s.w; if (s.pre) $('prefix').value = s.pre; if (s.suf) $('suffix').value = s.suf; } catch(e) {}
  const cfg = () => ({ q: +$('quality').value, kb: $('maxKB').value === '' ? 0 : Math.max(10, +$('maxKB').value), w: +$('maxW').value });
  const saveCfg = () => { try { const c = cfg(); localStorage.setItem('webp_cfg', JSON.stringify({ q: c.q, kb: $('maxKB').value, w: c.w, pre: $('prefix').value, suf: $('suffix').value })); } catch(e) {} };
  $('qv').textContent = $('quality').value;
  $('quality').addEventListener('input', () => { $('qv').textContent = $('quality').value; saveCfg(); });
  ['maxKB','maxW'].forEach(id => $(id).addEventListener('change', saveCfg));
  // Prefijo/sufijo: cambian los nombres al instante, sin reconvertir
  ['prefix','suffix'].forEach(id => $(id).addEventListener('input', () => { saveCfg(); render(); }));

  // ¿El navegador sabe generar WebP?
  const probe = document.createElement('canvas'); probe.width = probe.height = 1;
  const webpOK = probe.toDataURL('image/webp').startsWith('data:image/webp');
  if (!webpOK) { $('warn').style.display = 'block'; }

  async function decode(file){
    if ('createImageBitmap' in window) {
      try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch(e) {}
    }
    return await new Promise((res, rej) => { const i = new Image(); const u = URL.createObjectURL(file); i.onload = () => { URL.revokeObjectURL(u); res(i); }; i.onerror = () => { URL.revokeObjectURL(u); rej(new Error('No se pudo leer la imagen')); }; i.src = u; });
  }
  function encode(src, w, h, q){
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const ctx = c.getContext('2d'); ctx.imageSmoothingQuality = 'high'; ctx.drawImage(src, 0, 0, w, h);
    return new Promise(res => c.toBlob(res, 'image/webp', q));
  }
  // Busca la mejor calidad que entra en el peso; si ni al 30% entra, achica la imagen de a 15%.
  async function convert(file, opt){
    const img = await decode(file);
    const sw = img.width, sh = img.height;
    let w = sw, h = sh;
    if (opt.w && w > opt.w) { h = Math.round(h * opt.w / w); w = opt.w; }
    const max = opt.kb ? opt.kb * 1024 : Infinity;
    let blob = await encode(img, w, h, opt.q / 100), q = opt.q, shrunk = false;
    if (blob.size > max) {
      let lo = 30, hi = opt.q - 1, best = null;
      while (lo <= hi) {                       // búsqueda binaria de calidad
        const mid = (lo + hi) >> 1;
        const b = await encode(img, w, h, mid / 100);
        if (b.size <= max) { best = { b, q: mid }; lo = mid + 1; } else hi = mid - 1;
      }
      if (best) { blob = best.b; q = best.q; }
      else {
        q = 60;
        for (let i = 0; i < 12 && blob.size > max && w > 160; i++) {
          w = Math.round(w * .85); h = Math.round(h * .85); shrunk = true;
          blob = await encode(img, w, h, q / 100);
        }
      }
    }
    if (img.close) img.close();
    return { blob, w, h, q, sw, sh, shrunk, fits: blob.size <= max };
  }

  const clean = t => String(t).trim().replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, '_');
  function baseName(it){
    const pre = clean($('prefix').value), suf = clean($('suffix').value);
    const core = it.custom ? clean(it.custom) : clean(it.file.name.replace(/\.[^.]+$/, ''));
    return (pre ? pre + '_' : '') + core + (suf ? '_' + suf : '');
  }
  // Asigna nombres finales en orden, sin repetidos (si coinciden: -2, -3…)
  function assignNames(){
    const used = new Set();
    items.forEach(it => { let b = baseName(it), n = b + '.webp', k = 2; while (used.has(n.toLowerCase())) n = b + '-' + (k++) + '.webp'; used.add(n.toLowerCase()); it.name = n; });
    const ex = items[0] ? items[0].name : (( clean($('prefix').value) ? clean($('prefix').value) + '_' : '') + 'nombre-original' + (clean($('suffix').value) ? '_' + clean($('suffix').value) : '') + '.webp');
    $('namePreview').textContent = 'Ejemplo: ' + ex;
  }
  window.wpRename = function(id){
    const it = items.find(x => x.id === id); if (!it) return;
    const row = document.querySelector('[data-name-of="' + id + '"]'); if (!row) return;
    row.innerHTML = '<input class="wp-ren-input" value="' + esc(it.custom || it.file.name.replace(/\.[^.]+$/, '')) + '">';
    const inp = row.querySelector('input'); inp.focus(); inp.select();
    let done = false;
    const finish = save => { if (done) return; done = true; if (save) { const v = inp.value.trim(); it.custom = v || null; } render(); };
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') finish(true); if (e.key === 'Escape') finish(false); });
    inp.addEventListener('blur', () => finish(true));
  };

  function render(){
    assignNames();
    const list = $('list');
    if (!items.length) { list.innerHTML = '<div class="wp-empty">Las imágenes convertidas aparecen acá, con el peso antes y después.</div>'; }
    else list.innerHTML = items.map(it => {
      const pct = it.blob ? Math.round((1 - it.blob.size / it.file.size) * 100) : 0;
      const meta = it.state === 'ok'
        ? `${it.sw}×${it.sh}${it.w !== it.sw ? ' → ' + it.w + '×' + it.h : ''} · calidad ${it.q}%` + (it.shrunk ? ' · <span class="wp-state">se achicó para llegar al peso</span>' : '') + (!it.fits ? ' · <span class="wp-state">no llegó al peso pedido</span>' : '')
        : it.state === 'err' ? `<span class="wp-state err">${esc(it.msg)}</span>` : 'Convirtiendo…';
      return `<div class="wp-item">
        ${it.url ? `<img class="wp-thumb" src="${it.url}" alt="">` : '<span class="wp-thumb" style="display:flex;align-items:center;justify-content:center"><span class="wp-spin"></span></span>'}
        <div style="min-width:0"><div class="wp-name-row" data-name-of="${it.id}"><div class="wp-name" title="Original: ${esc(it.file.name)}">${esc(it.name)}</div><button class="wp-ren" type="button" title="Renombrar" data-on-click="wpRename" data-click-1="j:${it.id}"><i class="fa-solid fa-pen"></i></button></div><div class="wp-meta">${meta}</div></div>
        <div class="wp-sizes">${it.blob ? `<span class="from">${fmtKB(it.file.size)}</span> <span class="to">${fmtKB(it.blob.size)}</span><span class="pct ${pct < 0 ? 'bad' : ''}">${pct >= 0 ? '−' + pct + '%' : '+' + (-pct) + '%'}</span>` : ''}</div>
        ${it.url ? `<a class="wp-btn dl" href="${it.url}" download="${esc(it.name)}"><i class="fa-solid fa-download"></i></a>` : '<span></span>'}
      </div>`;
    }).join('');
    const done = items.filter(x => x.state === 'ok');
    const before = done.reduce((a, x) => a + x.file.size, 0), after = done.reduce((a, x) => a + x.blob.size, 0);
    $('sum').innerHTML = done.length ? `${done.length} ${done.length > 1 ? 'imágenes' : 'imagen'} · ${fmtKB(before)} → ${fmtKB(after)} · <b>ahorro ${Math.max(0, Math.round((1 - after / before) * 100))}%</b>` : (items.length ? 'Convirtiendo…' : 'Todavía no convertiste imágenes.');
    $('zip').disabled = !done.length || busy;
    $('clear').disabled = !items.length || busy;
    $('redo').disabled = !items.length || busy;
  }

  async function processQueue(){
    if (busy) return; busy = true; render();
    const opt = cfg();
    for (const it of items) {
      if (it.state !== 'pending') continue;
      try {
        const r = await convert(it.file, opt);
        if (r.blob.type !== 'image/webp') throw new Error('Tu navegador no generó WebP. Usa Chrome, Edge o Firefox.');
        if (it.url) URL.revokeObjectURL(it.url);
        Object.assign(it, r, { url: URL.createObjectURL(r.blob), state: 'ok' });
      } catch (e) { Object.assign(it, { state: 'err', msg: e.message || 'No se pudo convertir' }); }
      render();
    }
    busy = false; render();
  }

  function addFiles(files){
    const ok = [...files].filter(f => /^image\//.test(f.type));
    if (!ok.length) return;
    ok.forEach(f => items.push({ id: ++seq, file: f, state: 'pending' }));
    render(); processQueue();
  }

  // Entrada: clic, arrastrar y pegar
  $('file').addEventListener('change', e => { addFiles(e.target.files); e.target.value = ''; });
  const drop = $('drop');
  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => addFiles(e.dataTransfer.files));
  window.addEventListener('dragover', e => e.preventDefault());
  window.addEventListener('drop', e => { e.preventDefault(); if (!drop.contains(e.target)) addFiles(e.dataTransfer.files); });
  document.addEventListener('paste', e => { const f = [...(e.clipboardData?.files || [])]; if (f.length) addFiles(f); });

  $('redo').addEventListener('click', () => { items.forEach(it => { it.state = 'pending'; }); processQueue(); });
  $('clear').addEventListener('click', () => { items.forEach(it => it.url && URL.revokeObjectURL(it.url)); items.length = 0; render(); });
  $('zip').addEventListener('click', async () => {
    const done = items.filter(x => x.state === 'ok'); if (!done.length) return;
    const btn = $('zip'); btn.disabled = true; const label = btn.innerHTML; btn.innerHTML = '<span class="wp-spin"></span> Armando el .zip…';
    assignNames(); const zip = new JSZip(); done.forEach(x => zip.file(x.name, x.blob));
    const blob = await zip.generateAsync({ type: 'blob' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'imagenes_webp.zip';
    document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    btn.innerHTML = label; render();
  });
  render();
})();
