/* social/perfiles/render.js — Profile Studio: draws a profile picture from its state (the same function for the preview and the export).
 * Order: background → subject (photo or photo without background, with its adjustments, filter, shadow and glow) → vignette → frame →
 * sparkles → badge → shape (circle/rounded) when exporting. Everything is in fractions of the side, so any size looks the same.
 * Doc: toolz/SOCIAL_STUDIO.md (Profile Studio) */
(function () {
  'use strict';
  // Sizes that each network recommends for the profile picture (editable: "Personalizado")
  const PRESETS = {
    whatsapp: ['WhatsApp', 'fa-brands fa-whatsapp', '#25d366', 512], telegram: ['Telegram', 'fa-brands fa-telegram', '#29a9eb', 512],
    facebook: ['Facebook', 'fa-brands fa-facebook', '#1877f2', 176], instagram: ['Instagram', 'fa-brands fa-instagram', '#e1306c', 320],
    tiktok: ['TikTok', 'fa-brands fa-tiktok', '#25f4ee', 200], x: ['X', 'fa-brands fa-x-twitter', '#a1a1aa', 400],
    linkedin: ['LinkedIn', 'fa-brands fa-linkedin', '#0a66c2', 400], youtube: ['YouTube', 'fa-brands fa-youtube', '#ff0033', 800],
    twitch: ['Twitch', 'fa-brands fa-twitch', '#9146ff', 256], discord: ['Discord', 'fa-brands fa-discord', '#5865f2', 512],
    steam: ['Steam', 'fa-brands fa-steam', '#66c0f4', 184], custom: ['Personalizado', 'fa-solid fa-ruler-combined', '#94a3b8', 1024],
  };
  // Filters: CSS-like filter + optional color wash
  const FILTERS = {
    none: ['Original', '', null], vivid: ['Vívido', 'saturate(1.35) contrast(1.08)', null], warm: ['Cálido', 'saturate(1.1)', ['#ff9a3c', .16]],
    cool: ['Frío', 'saturate(1.05)', ['#3c8dff', .16]], bw: ['Blanco y negro', 'grayscale(1) contrast(1.1)', null], noir: ['Noir', 'grayscale(1) contrast(1.45) brightness(.92)', null],
    sepia: ['Sepia', 'sepia(.85) contrast(1.05)', null], fade: ['Suave', 'contrast(.85) brightness(1.08) saturate(.85)', ['#ffffff', .08]],
    drama: ['Drama', 'contrast(1.35) saturate(1.15) brightness(.95)', null], vintage: ['Vintage', 'sepia(.35) contrast(.92) saturate(.9)', ['#f5c36b', .12]],
    pop: ['Pop', 'saturate(1.7) contrast(1.15)', null], cyber: ['Cyber', 'saturate(1.3) hue-rotate(-12deg) contrast(1.1)', ['#b026ff', .14]],
  };
  // Backgrounds drawn by code (no images to download): category => list of [id, name]
  const BG_GROUPS = [
    ['Colores', [['solid', 'Color liso']]],
    ['Degradados', [['gradient', 'Degradado'], ['radial', 'Radial']]],
    ['Abstractos', [['mesh', 'Malla de color'], ['blobs', 'Manchas'], ['waves', 'Ondas']]],
    ['Gaming', [['neongrid', 'Grilla neón'], ['hex', 'Hexágonos'], ['rays', 'Rayos']]],
    ['Espacio', [['stars', 'Estrellas'], ['nebula', 'Nebulosa']]],
    ['Texturas', [['dots', 'Puntos'], ['lines', 'Rayas'], ['noise', 'Grano'], ['paper', 'Papel']]],
  ];
  const FRAMES = { none: 'Sin marco', ring: 'Aro', double: 'Doble aro', dashed: 'Punteado', gradient: 'Degradado', neon: 'Neón', glow: 'Luminoso', gamer: 'Gamer', minimal: 'Minimalista' };

  const defaults = () => ({
    v: 1, preset: 'instagram', shape: 'circle', guide: true,
    photo: null,   // {token, w, h, cutToken, x, y, scale, rot, flip}
    adj: { brightness: 0, contrast: 0, saturation: 0, temperature: 0, exposure: 0, sharpness: 0, blur: 0, vignette: 0 },
    filter: 'none',
    bg: { type: 'gradient', color: '#1e293b', color2: '#ff6600', angle: 135, token: null, w: 0, h: 0, x: 0, y: 0, scale: 1, blur: 0, dim: 0, seed: 7 },
    subject: { blurBg: 0 },
    frame: { type: 'none', color: '#ff6600', color2: '#7c3aed', width: 4, intensity: 60 },
    fx: { shadow: { on: false, color: '#000000', size: 40 }, glow: { on: false, color: '#ff6600', size: 50 }, sparkles: { on: false, color: '#ffffff', amount: 40, style: 'stars' } },
    badge: { on: false, kind: 'check', text: '', color: '#1d9bf0', pos: 'br', size: 24 },
  });

  // Seeded random (so the decorations do not move every time it is redrawn)
  const rng = seed => { let s = (seed >>> 0) || 1; return () => (s = (s * 1664525 + 1013904223) >>> 0) / 4294967296; };
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  function coverRect(iw, ih, N, p) {
    const base = Math.max(N / iw, N / ih), s = base * (p.scale || 1);
    return { w: iw * s, h: ih * s, cx: N / 2 + (p.x || 0) * N, cy: N / 2 + (p.y || 0) * N };
  }
  function drawPlaced(ctx, img, N, p) {
    const r = coverRect(img.naturalWidth || img.width, img.naturalHeight || img.height, N, p);
    ctx.save(); ctx.translate(r.cx, r.cy); ctx.rotate((p.rot || 0) * Math.PI / 180); if (p.flip) ctx.scale(-1, 1);
    ctx.drawImage(img, -r.w / 2, -r.h / 2, r.w, r.h); ctx.restore();
  }
  function adjFilter(a, f) {
    const b = 1 + (a.brightness || 0) / 100 + (a.exposure || 0) / 80, c = 1 + (a.contrast || 0) / 100, s = 1 + (a.saturation || 0) / 100;
    return `${(FILTERS[f] || FILTERS.none)[1]} brightness(${clamp(b, 0, 3)}) contrast(${clamp(c, 0, 3)}) saturate(${clamp(s, 0, 3)})`.trim();
  }
  function layer(N, readable) { const c = document.createElement('canvas'); c.width = c.height = N; if (readable) c.getContext('2d', { willReadFrequently: true }); return c; }
  // Sharpen (3x3 unsharp) on a layer: amount 0..100
  function sharpen(cv, amount) {
    if (!amount) return;
    const x = cv.getContext('2d'), N = cv.width, d = x.getImageData(0, 0, N, N), s = d.data, o = new Uint8ClampedArray(s.length), k = amount / 100 * 0.9, c0 = 1 + 4 * k, row = N * 4;
    o.set(s);
    for (let y = 1; y < N - 1; y++) {
      let i = y * row + 4;
      for (let xx = 1; xx < N - 1; xx++, i += 4) {
        if (s[i + 3] === 0) continue;
        o[i] = s[i] * c0 - k * (s[i - 4] + s[i + 4] + s[i - row] + s[i + row]);
        o[i + 1] = s[i + 1] * c0 - k * (s[i - 3] + s[i + 5] + s[i + 1 - row] + s[i + 1 + row]);
        o[i + 2] = s[i + 2] * c0 - k * (s[i - 2] + s[i + 6] + s[i + 2 - row] + s[i + 2 + row]);
      }
    }
    d.data.set(o); x.putImageData(d, 0, 0);
  }
  // Color wash over the opaque pixels of a layer (temperature and filter tints)
  function wash(cv, color, alpha, mode = 'soft-light') {
    if (!alpha) return;
    const x = cv.getContext('2d'); x.save(); x.globalCompositeOperation = 'source-atop'; x.globalAlpha = clamp(alpha, 0, 1);
    x.globalCompositeOperation = mode; x.fillStyle = color; x.fillRect(0, 0, cv.width, cv.height); x.restore();
    // keep the alpha of the subject: soft-light over transparent pixels paints them, so mask again
  }
  function maskTo(cv, mask) { const x = cv.getContext('2d'); x.save(); x.globalCompositeOperation = 'destination-in'; x.drawImage(mask, 0, 0); x.restore(); }

  /** The subject (photo with its adjustments) on its own layer. */
  function subjectLayer(img, N, S, plain) {
    const L = layer(N, !plain && !!S.adj.sharpness), x = L.getContext('2d');
    if (!plain) { x.filter = adjFilter(S.adj, S.filter); if (S.adj.blur) x.filter += ` blur(${S.adj.blur / 100 * N / 60}px)`; }
    drawPlaced(x, img, N, S.photo); x.filter = 'none';
    if (plain) return L;
    const alpha = layer(N); alpha.getContext('2d').drawImage(L, 0, 0);
    const t = S.adj.temperature || 0;
    if (t) wash(L, t > 0 ? '#ff8a2a' : '#2a8aff', Math.abs(t) / 100 * .45);
    const fw = (FILTERS[S.filter] || FILTERS.none)[2];
    if (fw) wash(L, fw[0], fw[1], 'overlay');
    if (t || fw) maskTo(L, alpha);
    sharpen(L, S.adj.sharpness || 0);
    return L;
  }

  function drawBackground(ctx, N, S, imgs) {
    const B = S.bg, R = rng(B.seed || 7), c1 = B.color, c2 = B.color2;
    const grad = (angle) => { const a = (angle || 0) * Math.PI / 180, dx = Math.cos(a) * N / 2, dy = Math.sin(a) * N / 2;
      const g = ctx.createLinearGradient(N / 2 - dx, N / 2 - dy, N / 2 + dx, N / 2 + dy); g.addColorStop(0, c1); g.addColorStop(1, c2); return g; };
    ctx.save();
    switch (B.type) {
      case 'none': break;
      case 'solid': ctx.fillStyle = c1; ctx.fillRect(0, 0, N, N); break;
      case 'gradient': ctx.fillStyle = grad(B.angle); ctx.fillRect(0, 0, N, N); break;
      case 'radial': { const g = ctx.createRadialGradient(N / 2, N * .42, 0, N / 2, N / 2, N * .75); g.addColorStop(0, c2); g.addColorStop(1, c1); ctx.fillStyle = g; ctx.fillRect(0, 0, N, N); break; }
      case 'mesh': {
        ctx.fillStyle = c1; ctx.fillRect(0, 0, N, N);
        [[c2, .2, .25], ['#7c3aed', .8, .3], ['#06b6d4', .3, .85], [c2, .85, .8]].forEach(([col, px, py]) => {
          const g = ctx.createRadialGradient(px * N, py * N, 0, px * N, py * N, N * .6); g.addColorStop(0, col + 'cc'); g.addColorStop(1, col + '00'); ctx.fillStyle = g; ctx.fillRect(0, 0, N, N); });
        break;
      }
      case 'blobs': { ctx.fillStyle = c1; ctx.fillRect(0, 0, N, N); ctx.filter = `blur(${N / 18}px)`;
        for (let i = 0; i < 6; i++) { ctx.fillStyle = (i % 2 ? c2 : '#ffffff') + (i % 2 ? 'b0' : '30'); ctx.beginPath(); ctx.arc(R() * N, R() * N, N * (.15 + R() * .25), 0, 7); ctx.fill(); }
        ctx.filter = 'none'; break; }
      case 'waves': { ctx.fillStyle = grad(90); ctx.fillRect(0, 0, N, N); ctx.strokeStyle = '#ffffff22'; ctx.lineWidth = N / 90;
        for (let k = 0; k < 9; k++) { ctx.beginPath(); for (let xx = 0; xx <= N; xx += N / 40) { const yy = N * (k / 8) + Math.sin(xx / N * 6 + k) * N * .04; xx ? ctx.lineTo(xx, yy) : ctx.moveTo(xx, yy); } ctx.stroke(); } break; }
      case 'neongrid': { ctx.fillStyle = c1; ctx.fillRect(0, 0, N, N);
        const g = ctx.createLinearGradient(0, N * .45, 0, N); g.addColorStop(0, c2 + '00'); g.addColorStop(1, c2 + '55'); ctx.fillStyle = g; ctx.fillRect(0, 0, N, N);
        ctx.strokeStyle = c2; ctx.lineWidth = N / 260; ctx.shadowColor = c2; ctx.shadowBlur = N / 60; const hz = N * .55;
        for (let i = 0; i <= 12; i++) { const yy = hz + (N - hz) * Math.pow(i / 12, 2); ctx.beginPath(); ctx.moveTo(0, yy); ctx.lineTo(N, yy); ctx.stroke(); }
        for (let i = -10; i <= 10; i++) { ctx.beginPath(); ctx.moveTo(N / 2 + i * N * .02, hz); ctx.lineTo(N / 2 + i * N * .16, N); ctx.stroke(); } break; }
      case 'hex': { ctx.fillStyle = c1; ctx.fillRect(0, 0, N, N); const r = N / 14, h = r * Math.sqrt(3);
        ctx.lineWidth = N / 300;
        for (let row = -1; row < N / h + 1; row++) for (let col = -1; col < N / (r * 1.5) + 1; col++) {
          const cx = col * r * 1.5, cy = row * h + (col % 2 ? h / 2 : 0); ctx.strokeStyle = c2 + (R() > .8 ? 'cc' : '40');
          ctx.beginPath(); for (let k = 0; k < 6; k++) { const a = Math.PI / 3 * k; ctx.lineTo(cx + r * .92 * Math.cos(a), cy + r * .92 * Math.sin(a)); } ctx.closePath(); ctx.stroke(); }
        break; }
      case 'rays': { ctx.fillStyle = c1; ctx.fillRect(0, 0, N, N); ctx.translate(N / 2, N / 2); ctx.fillStyle = c2 + '40';
        for (let i = 0; i < 18; i++) { ctx.rotate(Math.PI / 9); ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(N, -N * .09); ctx.lineTo(N, N * .09); ctx.fill(); } ctx.setTransform(1, 0, 0, 1, 0, 0); break; }
      case 'stars': { const g = ctx.createRadialGradient(N / 2, N / 2, 0, N / 2, N / 2, N * .8); g.addColorStop(0, '#1b2140'); g.addColorStop(1, '#05060f'); ctx.fillStyle = g; ctx.fillRect(0, 0, N, N);
        for (let i = 0; i < 320; i++) { ctx.fillStyle = `rgba(255,255,255,${.3 + R() * .7})`; const s = R() * N / 400 + N / 1200; ctx.fillRect(R() * N, R() * N, s, s); } break; }
      case 'nebula': { ctx.fillStyle = '#060714'; ctx.fillRect(0, 0, N, N); ctx.filter = `blur(${N / 14}px)`;
        [[c2, .3, .35, .35], ['#7c3aed', .7, .6, .4], ['#06b6d4', .5, .8, .3]].forEach(([col, px, py, r]) => { ctx.fillStyle = col + '99'; ctx.beginPath(); ctx.arc(px * N, py * N, r * N, 0, 7); ctx.fill(); });
        ctx.filter = 'none'; for (let i = 0; i < 180; i++) { ctx.fillStyle = `rgba(255,255,255,${.4 + R() * .6})`; const s = R() * N / 500 + N / 1500; ctx.fillRect(R() * N, R() * N, s, s); } break; }
      case 'dots': { ctx.fillStyle = c1; ctx.fillRect(0, 0, N, N); ctx.fillStyle = c2 + '66'; const st = N / 20;
        for (let yy = st / 2; yy < N; yy += st) for (let xx = st / 2; xx < N; xx += st) { ctx.beginPath(); ctx.arc(xx, yy, st * .14, 0, 7); ctx.fill(); } break; }
      case 'lines': { ctx.fillStyle = c1; ctx.fillRect(0, 0, N, N); ctx.strokeStyle = c2 + '44'; ctx.lineWidth = N / 60;
        for (let i = -N; i < N * 2; i += N / 14) { ctx.beginPath(); ctx.moveTo(i, 0); ctx.lineTo(i - N, N); ctx.stroke(); } break; }
      case 'noise': case 'paper': { ctx.fillStyle = B.type === 'paper' ? '#efe7d6' : c1; ctx.fillRect(0, 0, N, N);
        const d = ctx.getImageData(0, 0, N, N), p = d.data, amt = B.type === 'paper' ? 18 : 28;
        for (let i = 0; i < p.length; i += 4) { const n = (R() - .5) * amt; p[i] += n; p[i + 1] += n; p[i + 2] += n; }
        ctx.putImageData(d, 0, 0); break; }
      case 'image': {
        const im = imgs.bg; if (!im) break;
        ctx.filter = B.blur ? `blur(${B.blur / 100 * N / 25}px)` : 'none';
        drawPlaced(ctx, im, N, { x: B.x, y: B.y, scale: B.scale || 1, rot: 0 }); ctx.filter = 'none'; break;
      }
    }
    ctx.restore();
    if (B.dim && B.type !== 'none') { ctx.fillStyle = `rgba(0,0,0,${B.dim / 100 * .8})`; ctx.fillRect(0, 0, N, N); }
  }

  function framePath(ctx, N, shape, inset) {
    const r = N / 2 - inset; ctx.beginPath();
    if (shape === 'square') ctx.rect(inset, inset, N - inset * 2, N - inset * 2);
    else if (shape === 'rounded') ctx.roundRect(inset, inset, N - inset * 2, N - inset * 2, N * .18);
    else ctx.arc(N / 2, N / 2, r, 0, Math.PI * 2);
  }
  function drawFrame(ctx, N, S) {
    const F = S.frame; if (!F || F.type === 'none') return;
    const w = Math.max(1, F.width / 100 * N), k = (F.intensity ?? 60) / 100, sh = S.shape;
    ctx.save(); ctx.lineWidth = w;
    const grad = () => { const g = ctx.createLinearGradient(0, 0, N, N); g.addColorStop(0, F.color); g.addColorStop(1, F.color2); return g; };
    switch (F.type) {
      case 'ring': ctx.strokeStyle = F.color; framePath(ctx, N, sh, w / 2); ctx.stroke(); break;
      case 'double': ctx.strokeStyle = F.color; ctx.lineWidth = w * .45; framePath(ctx, N, sh, w * .25); ctx.stroke(); framePath(ctx, N, sh, w * 1.05); ctx.stroke(); break;
      case 'dashed': ctx.strokeStyle = F.color; ctx.setLineDash([w * 1.6, w * 1.1]); ctx.lineCap = 'round'; framePath(ctx, N, sh, w / 2); ctx.stroke(); break;
      case 'gradient': ctx.strokeStyle = grad(); framePath(ctx, N, sh, w / 2); ctx.stroke(); break;
      case 'neon': ctx.strokeStyle = F.color; ctx.shadowColor = F.color; ctx.shadowBlur = N * .06 * k + 2; ctx.lineWidth = w * .55;
        for (let i = 0; i < 2; i++) { framePath(ctx, N, sh, w); ctx.stroke(); } ctx.shadowBlur = 0; ctx.strokeStyle = '#ffffff'; ctx.lineWidth = w * .18; framePath(ctx, N, sh, w); ctx.stroke(); break;
      case 'glow': ctx.strokeStyle = grad(); ctx.shadowColor = F.color; ctx.shadowBlur = N * .08 * k + 2; framePath(ctx, N, sh, w * .9); ctx.stroke(); break;
      case 'gamer': { ctx.strokeStyle = grad(); framePath(ctx, N, sh, w / 2); ctx.stroke(); ctx.fillStyle = F.color; ctx.shadowColor = F.color; ctx.shadowBlur = N * .03 * k;
        for (let i = 0; i < 4; i++) { ctx.save(); ctx.translate(N / 2, N / 2); ctx.rotate(Math.PI / 4 + i * Math.PI / 2); ctx.beginPath(); ctx.moveTo(N / 2 - w * 2.4, -w * 1.2); ctx.lineTo(N / 2 - w * .2, 0); ctx.lineTo(N / 2 - w * 2.4, w * 1.2); ctx.closePath(); ctx.fill(); ctx.restore(); }
        break; }
      case 'minimal': ctx.strokeStyle = F.color; ctx.globalAlpha = .35 + .65 * k; ctx.lineWidth = Math.max(1, w * .35); framePath(ctx, N, sh, w * 1.3); ctx.stroke(); break;
    }
    ctx.restore();
  }
  function drawSparkles(ctx, N, S) {
    const P = S.fx.sparkles; if (!P || !P.on) return;
    const R = rng((S.bg.seed || 7) * 31 + 5), n = Math.round(4 + (P.amount || 40) / 100 * 60);
    ctx.save(); ctx.fillStyle = P.color; ctx.shadowColor = P.color; ctx.shadowBlur = N / 80;
    for (let i = 0; i < n; i++) {
      const a = R() * Math.PI * 2, rr = N * (.3 + R() * .2), x = N / 2 + Math.cos(a) * rr, y = N / 2 + Math.sin(a) * rr, s = N * (.004 + R() * .012);
      ctx.globalAlpha = .4 + R() * .6;
      if (P.style === 'dots') { ctx.beginPath(); ctx.arc(x, y, s * .6, 0, 7); ctx.fill(); }
      else if (P.style === 'bokeh') { ctx.globalAlpha *= .45; ctx.beginPath(); ctx.arc(x, y, s * 3, 0, 7); ctx.fill(); }
      else { ctx.beginPath(); for (let k = 0; k < 8; k++) { const r2 = k % 2 ? s * .3 : s * 1.6, b = k * Math.PI / 4; ctx.lineTo(x + Math.cos(b) * r2, y + Math.sin(b) * r2); } ctx.closePath(); ctx.fill(); }
    }
    ctx.restore();
  }
  function drawBadge(ctx, N, S) {
    const B = S.badge; if (!B || !B.on) return;
    const r = N * (B.size || 24) / 200, off = N / 2 + (N / 2 - r * 1.05) * Math.SQRT1_2 * (S.shape === 'circle' ? 1 : 1.25);
    const cx = B.pos.endsWith('l') ? N - off : off, cy = B.pos.startsWith('t') ? N - off : off;
    ctx.save(); ctx.shadowColor = 'rgba(0,0,0,.35)'; ctx.shadowBlur = r * .4;
    ctx.fillStyle = B.color; ctx.beginPath(); ctx.arc(cx, cy, r, 0, 7); ctx.fill(); ctx.shadowBlur = 0;
    ctx.lineWidth = r * .16; ctx.strokeStyle = '#ffffff'; ctx.stroke();
    if (B.kind === 'check') { ctx.lineWidth = r * .2; ctx.lineCap = ctx.lineJoin = 'round'; ctx.beginPath(); ctx.moveTo(cx - r * .42, cy + r * .02); ctx.lineTo(cx - r * .1, cy + r * .34); ctx.lineTo(cx + r * .45, cy - r * .3); ctx.stroke(); }
    else if (B.kind === 'dot') { ctx.fillStyle = '#ffffff'; ctx.beginPath(); ctx.arc(cx, cy, r * .3, 0, 7); ctx.fill(); }
    else { const t = String(B.text || '').slice(0, 3); ctx.fillStyle = '#ffffff'; ctx.font = `700 ${r * (t.length > 2 ? .75 : t.length > 1 ? .9 : 1.1)}px Inter, system-ui, "Segoe UI Emoji", sans-serif`; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(t, cx, cy + r * .05); }
    ctx.restore();
  }

  /**
   * Draws S on a canvas of side N. imgs: {photo, cut, bg} already loaded. opts: plain (only the photo, for "before"),
   * clip (cut the shape: transparent corners), transparent (do not fill what is empty), guide (circle guide for the preview).
   */
  function draw(canvas, S, imgs, opts = {}) {
    const N = canvas.width, ctx = canvas.getContext('2d');
    ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, N, N); ctx.filter = 'none'; ctx.globalAlpha = 1;
    const usePhotoBg = !S.photo || !S.photo.cutToken || !imgs.cut || S.bg.type === 'photo';
    if (opts.plain) { if (imgs.photo && S.photo) ctx.drawImage(subjectLayer(imgs.photo, N, S, true), 0, 0); return; }
    if (!usePhotoBg || !S.photo || !imgs.photo) drawBackground(ctx, N, S, imgs);
    if (S.photo && imgs.photo) {
      if (usePhotoBg) {
        // Background of the photo itself (optionally blurred behind the subject when there is a cutout)
        if (S.bg.type === 'photo' && imgs.cut && S.subject.blurBg) {
          const bgL = layer(N), bx = bgL.getContext('2d'); bx.filter = adjFilter(S.adj, S.filter) + ` blur(${S.subject.blurBg / 100 * N / 25}px)`; drawPlaced(bx, imgs.photo, N, S.photo);
          ctx.drawImage(bgL, 0, 0); ctx.drawImage(subjectLayer(imgs.cut, N, S), 0, 0);
        } else ctx.drawImage(subjectLayer(imgs.photo, N, S), 0, 0);
      } else {
        const L = subjectLayer(imgs.cut, N, S);
        const fxs = S.fx;
        if (fxs.glow.on) { ctx.save(); ctx.filter = `drop-shadow(0 0 ${fxs.glow.size / 100 * N / 12}px ${fxs.glow.color})`; ctx.drawImage(L, 0, 0); ctx.restore(); }
        if (fxs.shadow.on) { ctx.save(); ctx.filter = `drop-shadow(${N / 120}px ${N / 60}px ${fxs.shadow.size / 100 * N / 16}px ${fxs.shadow.color}cc)`; ctx.drawImage(L, 0, 0); ctx.restore(); }
        ctx.drawImage(L, 0, 0);
      }
    }
    if (S.adj.vignette) { const g = ctx.createRadialGradient(N / 2, N / 2, N * .25, N / 2, N / 2, N * .72); g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, `rgba(0,0,0,${S.adj.vignette / 100 * .85})`); ctx.fillStyle = g; ctx.fillRect(0, 0, N, N); }
    drawFrame(ctx, N, S); drawSparkles(ctx, N, S); drawBadge(ctx, N, S);
    if (opts.clip && S.shape !== 'square') { ctx.save(); ctx.globalCompositeOperation = 'destination-in'; ctx.fillStyle = '#000'; framePath(ctx, N, S.shape, 0); ctx.fill(); ctx.restore(); }   // opaque fill: the mask only uses its alpha (the last fill could be the transparent vignette)
    if (opts.fill) { ctx.save(); ctx.globalCompositeOperation = 'destination-over'; ctx.fillStyle = opts.fill; ctx.fillRect(0, 0, N, N); ctx.restore(); }
  }

  // ── Templates (Stage 2): a full starting point over the person's photo. cat: category; s: what changes of the defaults ──
  const TPL_CATS = { gaming: 'Gaming', stream: 'Streaming', pro: 'Profesional', redes: 'Redes', estilo: 'Estilo' };
  const TEMPLATES = [
    ['gamer', 'Gamer neón', 'gaming', { preset: 'discord', bg: { type: 'neongrid', color: '#0b0620', color2: '#ff2bd6' }, frame: { type: 'neon', color: '#ff2bd6', color2: '#00e5ff', width: 4, intensity: 80 }, fx: { glow: { on: true, color: '#ff2bd6', size: 55 } } }],
    ['gamer-hex', 'Gamer hexágonos', 'gaming', { preset: 'steam', bg: { type: 'hex', color: '#020617', color2: '#22d3ee' }, frame: { type: 'gamer', color: '#22d3ee', color2: '#a855f7', width: 3, intensity: 70 }, filter: 'cyber' }],
    ['esports', 'Esports', 'gaming', { preset: 'discord', bg: { type: 'rays', color: '#140404', color2: '#ef4444' }, frame: { type: 'gamer', color: '#f97316', color2: '#ef4444', width: 3.5, intensity: 80 }, fx: { glow: { on: true, color: '#f97316', size: 45 } }, filter: 'drama' }],
    ['fantasy', 'Fantasía RPG', 'gaming', { preset: 'discord', bg: { type: 'nebula', color: '#0b1020', color2: '#a855f7' }, frame: { type: 'glow', color: '#fbbf24', color2: '#a855f7', width: 3, intensity: 70 }, fx: { sparkles: { on: true, color: '#fde68a', amount: 55, style: 'stars' } } }],
    ['streamer', 'Streamer en vivo', 'stream', { preset: 'twitch', bg: { type: 'nebula', color: '#0b0614', color2: '#9146ff' }, frame: { type: 'glow', color: '#9146ff', color2: '#ec4899', width: 3.5, intensity: 75 }, badge: { on: true, kind: 'dot', color: '#ef4444', pos: 'br', size: 22 } }],
    ['twitch', 'Twitch', 'stream', { preset: 'twitch', bg: { type: 'gradient', color: '#1f0b3a', color2: '#9146ff', angle: 135 }, frame: { type: 'neon', color: '#bf94ff', width: 3.5, intensity: 70 }, fx: { sparkles: { on: true, color: '#e9d5ff', amount: 35, style: 'dots' } } }],
    ['youtube', 'YouTube', 'stream', { preset: 'youtube', bg: { type: 'radial', color: '#1a0000', color2: '#ff0033' }, frame: { type: 'ring', color: '#ffffff', width: 2.5 }, badge: { on: true, kind: 'check', color: '#ff0033', pos: 'br', size: 24 } }],
    ['discord', 'Discord', 'stream', { preset: 'discord', bg: { type: 'gradient', color: '#404eed', color2: '#5865f2', angle: 120 }, frame: { type: 'minimal', color: '#ffffff', width: 3, intensity: 80 }, badge: { on: true, kind: 'dot', color: '#23a55a', pos: 'br', size: 24 } }],
    ['linkedin', 'Profesional (LinkedIn)', 'pro', { preset: 'linkedin', bg: { type: 'radial', color: '#0f172a', color2: '#64748b' }, adj: { brightness: 6, contrast: 8 }, badge: { on: true, kind: 'check', color: '#0a66c2', pos: 'br', size: 22 } }],
    ['corporativo', 'Corporativo claro', 'pro', { preset: 'linkedin', bg: { type: 'gradient', color: '#e2e8f0', color2: '#f8fafc', angle: 90 }, frame: { type: 'ring', color: '#1e3a8a', width: 1.5 }, adj: { brightness: 4, contrast: 6 } }],
    ['ejecutivo', 'Ejecutivo', 'pro', { preset: 'linkedin', bg: { type: 'solid', color: '#1f2937' }, adj: { contrast: 10, vignette: 35 }, filter: 'fade', frame: { type: 'minimal', color: '#d4af37', width: 2.5, intensity: 70 } }],
    ['creativo', 'Creativo', 'pro', { preset: 'instagram', bg: { type: 'mesh', color: '#1e1b4b', color2: '#ff6600' }, frame: { type: 'gradient', color: '#ff6600', color2: '#ec4899', width: 4 } }],
    ['instagram', 'Instagram', 'redes', { preset: 'instagram', bg: { type: 'gradient', color: '#833ab4', color2: '#fcb045', angle: 45 }, frame: { type: 'gradient', color: '#fd1d1d', color2: '#fcb045', width: 4 } }],
    ['whatsapp', 'WhatsApp', 'redes', { preset: 'whatsapp', bg: { type: 'solid', color: '#d1fae5' }, frame: { type: 'ring', color: '#25d366', width: 3 } }],
    ['comunidad', 'Comunidad', 'redes', { preset: 'facebook', bg: { type: 'blobs', color: '#fff7ed', color2: '#f97316' }, frame: { type: 'double', color: '#f97316', width: 3 }, badge: { on: true, kind: 'text', text: '\u2605', color: '#f97316', pos: 'br', size: 24 } }],
    ['tiktok', 'TikTok', 'redes', { preset: 'tiktok', bg: { type: 'solid', color: '#010101' }, frame: { type: 'gradient', color: '#25f4ee', color2: '#fe2c55', width: 4 }, fx: { glow: { on: true, color: '#25f4ee', size: 40 } } }],
    ['dark', 'Dark aesthetic', 'estilo', { preset: 'instagram', bg: { type: 'solid', color: '#0a0a0a' }, filter: 'drama', adj: { vignette: 60 }, frame: { type: 'minimal', color: '#ffffff', width: 2, intensity: 60 } }],
    ['cyber', 'Cyberpunk', 'estilo', { preset: 'x', bg: { type: 'neongrid', color: '#030712', color2: '#22d3ee' }, filter: 'cyber', frame: { type: 'neon', color: '#22d3ee', width: 3.5, intensity: 85 } }],
    ['espacio', 'Espacial', 'estilo', { preset: 'x', bg: { type: 'stars', color: '#05060f' }, frame: { type: 'glow', color: '#7c3aed', color2: '#06b6d4', width: 3, intensity: 70 }, fx: { sparkles: { on: true, color: '#ffffff', amount: 40, style: 'stars' } } }],
    ['minimal', 'Minimalista B/N', 'estilo', { preset: 'instagram', bg: { type: 'solid', color: '#e5e7eb' }, filter: 'bw' }],
    ['vintage', 'Vintage', 'estilo', { preset: 'facebook', bg: { type: 'paper' }, filter: 'vintage', frame: { type: 'dashed', color: '#7c5a3a', width: 2.5 }, adj: { vignette: 30 } }],
  ].map(([id, name, cat, s]) => ({ id, name, cat, s }));
  /** Applies a template over a state: keeps the photo (and its cutout) and the framing; replaces the look. */
  function applyTemplate(S, id) {
    const t = TEMPLATES.find(x => x.id === id); if (!t) return S;
    const d = defaults(), o = { ...d, photo: S.photo, guide: S.guide, tpl: id };
    for (const k of ['adj', 'bg', 'frame', 'badge', 'subject']) o[k] = Object.assign({}, d[k], t.s[k] || {});
    o.fx = { shadow: Object.assign({}, d.fx.shadow, t.s.fx?.shadow), glow: Object.assign({}, d.fx.glow, t.s.fx?.glow), sparkles: Object.assign({}, d.fx.sparkles, t.s.fx?.sparkles) };
    o.filter = t.s.filter || 'none'; o.preset = t.s.preset || S.preset; o.shape = t.s.shape || 'circle';
    return o;
  }
  /** Main color of a template (frame, glow, badge, second color of the background), changed at once. */
  function recolor(S, main) {
    S.frame.color = main; S.fx.glow.color = main; if (S.badge.on && S.badge.kind !== 'dot') S.badge.color = main;
    if (!['solid', 'paper', 'stars', 'image', 'photo', 'none'].includes(S.bg.type)) S.bg.color2 = main;
    if (S.fx.sparkles.on && S.fx.sparkles.color.toLowerCase() !== '#ffffff') S.fx.sparkles.color = main;
    return S;
  }
  // Neutral silhouette (for the thumbnails of the templates and the preview without a photo)
  let SIL = null;
  function silhouette() {
    if (SIL) return SIL;
    const c = document.createElement('canvas'); c.width = 600; c.height = 800; const x = c.getContext('2d');
    const g = x.createLinearGradient(0, 0, 0, 800); g.addColorStop(0, '#cbd5e1'); g.addColorStop(1, '#94a3b8'); x.fillStyle = g;
    x.beginPath(); x.ellipse(300, 330, 118, 140, 0, 0, Math.PI * 2); x.fill();
    x.beginPath(); x.ellipse(300, 790, 250, 260, 0, Math.PI, 0); x.fill();
    return (SIL = c);
  }

  window.PFR = { PRESETS, FILTERS, BG_GROUPS, FRAMES, TEMPLATES, TPL_CATS, defaults, draw, coverRect, framePath, applyTemplate, recolor, silhouette };
})();
