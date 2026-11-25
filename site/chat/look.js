/* chat/look.js — Aspecto y sonido del chat, elegidos por cada persona: fondo y color de burbujas (para todos los chats), y fondo y sonido
   propios de una conversación. Lo usan el chat completo (chat.js) y la burbuja (widget.js). Se guarda con api 'look_save'. ASPECTO_CHAT.md */
window.CCLook = (() => {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const svg = (w, h, s) => `url("data:image/svg+xml,${encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' width='${w}' height='${h}'>${s}</svg>`)}")`;
  const BG = {
    noche: ['Noche', 'linear-gradient(160deg,#0f172a,#1e1b4b)'], aurora: ['Aurora', 'linear-gradient(150deg,#064e3b,#0f766e 45%,#312e81)'], atardecer: ['Atardecer', 'linear-gradient(160deg,#7c2d12,#9d174d 55%,#4c1d95)'],
    oceano: ['Océano', 'linear-gradient(170deg,#0c4a6e,#155e75 60%,#134e4a)'], durazno: ['Durazno', 'linear-gradient(160deg,#ffe4d6,#fbc4b5)'], menta: ['Menta', 'linear-gradient(160deg,#dcfce7,#bfe8dc)'],
    lavanda: ['Lavanda', 'linear-gradient(160deg,#ede9fe,#d8cdf5)'], carbon: ['Carbón', 'linear-gradient(#17181b,#17181b)'],
    puntos: ['Puntos', 'radial-gradient(rgba(255,255,255,.13) 1.5px,transparent 1.6px) 0 0/20px 20px,#1b2030'], grilla: ['Grilla', 'linear-gradient(rgba(255,255,255,.06) 1px,transparent 1px) 0 0/26px 26px,linear-gradient(90deg,rgba(255,255,255,.06) 1px,transparent 1px) 0 0/26px 26px,#14231f'],
    ondas: ['Ondas', svg(80, 24, "<path d='M0 12q20-14 40 0t40 0' fill='none' stroke='white' stroke-opacity='.11' stroke-width='2'/>") + ' 0 0/80px 24px,#1f2a44'], rombos: ['Rombos', svg(40, 40, "<path d='M20 4l16 16-16 16L4 20z' fill='none' stroke='black' stroke-opacity='.08' stroke-width='2'/>") + ' 0 0/40px 40px,#f1ece4'],
  };
  const COLORS = ['#2563eb', '#7c3aed', '#be185d', '#b91c1c', '#c2410c', '#15803d', '#0f766e', '#334155', '#bfdbfe', '#ddd6fe', '#fbcfe8', '#fed7aa', '#bbf7d0', '#e2e8f0'];
  const isHex = c => /^#[0-9a-f]{6}$/i.test(c || '');
  let cfg = { bg: '', mine: '', theirs: '', dim: 25, conv: {} }, api = null, boxes = [];
  const norm = c => ({ bg: '', mine: '', theirs: '', dim: 25, ...(c || {}), conv: c && c.conv && !Array.isArray(c.conv) ? c.conv : {} });
  const bgOf = (id, c = cfg) => { const v = (c.conv[id] || {}).bg; return v ? (v === 'none' ? '' : v) : c.bg; };
  const cssOf = (bg, dim) => {
    if (bg.startsWith('img:')) { const d = `rgba(0,0,0,${Math.max(0, Math.min(80, +dim || 0)) / 100})`; return `linear-gradient(${d},${d}),url("/toolz/chat/api.php?action=look_img&f=${encodeURIComponent(bg.slice(4))}") center/cover no-repeat`; }
    return BG[bg] ? BG[bg][1] : '';
  };
  const lum = h => { const v = [1, 3, 5].map(i => parseInt(h.substr(i, 2), 16) / 255).map(x => x <= .03928 ? x / 12.92 : ((x + .055) / 1.055) ** 2.4); return .2126 * v[0] + .7152 * v[1] + .0722 * v[2]; };
  // Blanco o negro, el que más contraste dé
  const ink = h => { const l = lum(h); return (l + .05) / .05 > 1.05 / (l + .05) ? '#101014' : '#ffffff'; };
  /** Pone el aspecto de una conversación en su lista de mensajes */
  function apply(box, id, c = cfg) {
    if (!box) return;
    const bg = cssOf(bgOf(id, c) || '', c.dim), sig = [bg, c.mine, c.theirs].join('|');
    if (box.__lk !== sig) {
      box.__lk = sig;
      box.classList.toggle('lk-bg', !!bg); box.classList.toggle('lk-b', !!(bg || c.mine || c.theirs));
      box.style.background = bg;
      [['me', c.mine], ['ot', c.theirs]].forEach(([k, v]) => {
        box.classList.toggle('lk-' + k, isHex(v));
        if (isHex(v)) { box.style.setProperty('--lk-' + k, v); box.style.setProperty(`--lk-${k}-t`, ink(v)); } else { box.style.removeProperty('--lk-' + k); box.style.removeProperty(`--lk-${k}-t`); }
      });
    }
    const b = boxes.find(x => x.box === box); if (b) b.id = id; else boxes.push({ box, id });
    boxes = boxes.filter(x => x.box.isConnected);
  }
  const reapply = c => boxes.forEach(b => apply(b.box, b.id, c));
  const soundOf = id => (cfg.conv[id] || {}).snd || '';

  // ── Diálogo ──
  let dlg = null;
  function open(id, name) {
    let d = norm(JSON.parse(JSON.stringify(cfg))), tab = 'conv', saved = false;
    const SND = window.G7Juice && G7Juice.sounds;
    if (!dlg) { dlg = document.createElement('dialog'); dlg.className = 'lkd'; document.body.append(dlg); dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); }); dlg.addEventListener('keydown', e => e.stopPropagation()); }
    dlg.onclose = () => { if (!saved) reapply(cfg); };
    const cv = () => d.conv[id] || {};
    const setCv = (k, v) => { const o = { ...cv() }; if (v) o[k] = v; else delete o[k]; if (Object.keys(o).length) d.conv[id] = o; else delete d.conv[id]; };
    const sw = (v, label, css, cur) => `<button type="button" role="radio" aria-checked="${v === cur}" class="lk-sw${v === cur ? ' on' : ''}" data-bg="${esc(v)}" title="${esc(label)}"><span style="${css ? `background:${esc(css)}` : ''}"></span>${esc(label)}</button>`;
    const bgs = cur => `<div class="lk-bgs" role="radiogroup" aria-label="Fondo">${tab === 'conv' ? sw('', 'Como los demás', '', cur) + sw('none', 'Sin fondo', '', cur) : sw('', 'Sin fondo', '', cur)}${Object.entries(BG).map(([k, b]) => sw(k, b[0], b[1], cur)).join('')}${cur.startsWith('img:') ? sw(cur, 'Tu imagen', cssOf(cur, 0), cur) : ''}<button type="button" class="lk-sw" data-up><span><i class="fa-solid fa-image"></i></span>Subir imagen</button></div><input type="file" accept="image/jpeg,image/png,image/webp" hidden>`;
    const cols = (k, label) => `<div class="lk-f"><b>${label}</b><div class="lk-cols"><button type="button" data-c="" data-k="${k}" class="${d[k] ? '' : 'on'}" title="Del tema" aria-label="${label}: del tema"><i class="fa-solid fa-ban"></i></button>${COLORS.map(c => `<button type="button" data-c="${c}" data-k="${k}" class="${d[k] === c ? 'on' : ''}" style="background:${c}" aria-label="${label}: ${c}"></button>`).join('')}<input type="color" data-k="${k}" value="${isHex(d[k]) ? d[k] : '#2563eb'}" aria-label="${label}: otro color"></div></div>`;
    const anyImg = () => (tab === 'conv' ? bgOf(id, d) : d.bg || '').startsWith('img:');
    function draw() {
      const top = dlg.querySelector('.lk-body')?.scrollTop || 0, cur = tab === 'conv' ? cv().bg || '' : d.bg || '';
      const g = {}; if (SND) Object.entries(SND.list).forEach(([k, s]) => (g[s[1]] ||= []).push([k, s[0]]));
      dlg.innerHTML = `<header><h2>Aspecto y sonido</h2><button type="button" class="lk-x" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
        <div class="lk-tabs" role="tablist"><button type="button" role="tab" data-t="conv" aria-selected="${tab === 'conv'}">${esc(name)}</button><button type="button" role="tab" data-t="all" aria-selected="${tab === 'all'}">Todos los chats</button></div>
        <div class="lk-body"><div class="lk-prev cc-msgs ccw-msgs"><div class="ccw-m"><div class="ccw-b">Hola, ¿cómo va la placa?</div></div><div class="ccw-m me"><div class="ccw-b">Lista en un rato 🎨</div></div></div>
        <div class="lk-f"><b>Fondo</b>${bgs(cur)}</div>
        ${anyImg() ? `<label class="lk-f"><b>Oscurecer la imagen</b><input type="range" min="0" max="80" step="5" value="${+d.dim}" data-dim></label>` : ''}
        ${tab === 'conv' ? `<div class="lk-f"><b>Sonido de sus mensajes</b><div class="lk-snd"><select aria-label="Sonido"><option value="">El de siempre</option><option value="none"${cv().snd === 'none' ? ' selected' : ''}>Sin sonido</option>${Object.entries(g).map(([n, l]) => `<optgroup label="${esc(n)}">${l.map(([k, t]) => `<option value="${k}"${cv().snd === k ? ' selected' : ''}>${esc(t)}</option>`).join('')}</optgroup>`).join('')}</select><button type="button" data-play aria-label="Escuchar"><i class="fa-solid fa-play"></i></button></div></div>`
          : cols('mine', 'Tus burbujas') + cols('theirs', 'Las de los demás')}
        <p class="lk-note">Solo lo ves vos. El color del texto se ajusta para que se lea.</p><div class="lk-e" role="alert"></div></div>
        <footer><button type="button" class="lk-btn" data-x>Cancelar</button><button type="button" class="lk-btn pri" data-go>Guardar</button></footer>`;
      dlg.querySelector('.lk-body').scrollTop = top; paint();
    }
    const paint = () => { const p = dlg.querySelector('.lk-prev'); p.__lk = null; apply(p, tab === 'conv' ? id : -1, d); boxes = boxes.filter(b => b.box !== p); reapply(d); };
    dlg.onclick = async e => {
      const q = s => e.target.closest(s);
      if (q('.lk-x,[data-x]')) { dlg.close(); return; }
      const t = q('[data-t]'); if (t) { tab = t.dataset.t; draw(); dlg.querySelector(`[data-t=${tab}]`).focus(); return; }
      if (q('[data-up]')) { dlg.querySelector('input[type=file]').click(); return; }
      const b = q('[data-bg]'); if (b) { if (tab === 'conv') setCv('bg', b.dataset.bg); else d.bg = b.dataset.bg; draw(); return; }
      const c = q('button[data-c]'); if (c) { d[c.dataset.k] = c.dataset.c; draw(); return; }
      if (q('[data-play]')) { const s = cv().snd; if (s === 'none') return; if (s) SND.play(s); else SND.test('dm'); return; }
      const go = q('[data-go]'); if (go) {
        go.disabled = true;
        try { const r = await api('look_save', { look: d }); cfg = norm(r.look); saved = true; reapply(cfg); dlg.close(); }
        catch (err) { dlg.querySelector('.lk-e').textContent = err.message; go.disabled = false; }
      }
    };
    dlg.oninput = e => { if (e.target.type === 'color') { d[e.target.dataset.k] = e.target.value; paint(); } if (e.target.matches('[data-dim]')) { d.dim = +e.target.value; paint(); } };
    dlg.onchange = async e => {
      if (e.target.type === 'color') { draw(); return; }
      if (e.target.matches('.lk-snd select')) { setCv('snd', e.target.value); if (e.target.value && e.target.value !== 'none') SND && SND.play(e.target.value); return; }
      if (e.target.type === 'file' && e.target.files[0]) {
        const er = dlg.querySelector('.lk-e'); er.textContent = 'Subiendo…';
        try { const f = await shrink(e.target.files[0]), fd = new FormData(); fd.append('action', 'look_img'); fd.append('img', f, 'f.jpg');
          const r = await fetch('/toolz/chat/api.php', { method: 'POST', headers: { 'X-CH': '1' }, body: fd }), j = await r.json().catch(() => null);
          if (!j || !j.ok) throw new Error((j && j.error) || 'No se pudo subir la imagen');
          if (tab === 'conv') setCv('bg', 'img:' + j.file); else d.bg = 'img:' + j.file; draw(); }
        catch (err) { er.textContent = err.message; }
      }
    };
    draw(); dlg.showModal();
  }
  /** Achica la imagen en el navegador: JPG de hasta 1600 px */
  async function shrink(file) {
    let bm; try { bm = await createImageBitmap(file); } catch (e) { throw new Error('Esa imagen no se puede abrir'); }
    const k = Math.min(1, 1600 / Math.max(bm.width, bm.height)), c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(bm.width * k)); c.height = Math.max(1, Math.round(bm.height * k));
    c.getContext('2d').drawImage(bm, 0, 0, c.width, c.height);
    return new Promise((ok, no) => c.toBlob(b => b ? ok(b) : no(new Error('Esa imagen no se puede abrir')), 'image/jpeg', .82));
  }
  return { init(c, a) { cfg = norm(c); api = a; reapply(cfg); }, apply, soundOf, open, get cfg() { return cfg; } };
})();
