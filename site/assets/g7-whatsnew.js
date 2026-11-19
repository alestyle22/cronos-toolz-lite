/* assets/g7-whatsnew.js — Ventana "Qué hay de nuevo": slider animado con lo nuevo de la versión (novedades_lib.php).
   Se abre sola al entrar si topbar.php dejó #g7-whatsnew-data (hay una versión nueva que la persona no vio); al cerrarla
   avisa a novedades_api.php y no vuelve hasta la próxima. "Novedades" en el menú de la persona ([data-g7-whatsnew]) la reabre.
   Teclado: ← → para moverse, Esc para cerrar. En el teléfono, deslizar. Sin movimiento si la persona lo apagó (html.g7-move). */
(() => {
  'use strict';
  const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const moving = () => document.documentElement.classList.contains('g7-move');
  let css = false;
  const SCRIPT_SRC = document.currentScript ? document.currentScript.src : '/toolz/assets/g7-whatsnew.js';
  async function api(action, data = {}) {
    const r = await fetch('/toolz/novedades_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Nov': '1' }, credentials: 'same-origin', body: JSON.stringify({ action, ...data }) });
    return r.json().catch(() => ({}));
  }

  // Diapositivas: una de presentación por versión y después una por novedad
  function build(releases) {
    const out = [];
    releases.forEach(r => {
      const n = r.slides.length;
      out.push({ intro: true, version: r.version, icon: 'fa-wand-magic-sparkles', color: r.slides[0]?.color || '#ff6a1a',
        title: r.title || 'Qué hay de nuevo', text: `Llegó la versión ${r.version}. Te mostramos lo nuevo en ${n} ${n === 1 ? 'paso' : 'pasos'}: tarda menos de un minuto.`,
        chips: r.slides.slice(0, 3).map(s => [s.icon, s.title.length > 26 ? s.title.slice(0, 24) + '…' : s.title]) });
      r.slides.forEach(s => out.push({ ...s, version: r.version }));
    });
    return out;
  }

  let open = false;
  async function show(releases, markSeen) {
    if (open || !releases || !releases.length) return; open = true;
    await loadCssFrom();
    const S = build(releases), latest = releases[0].version;
    let i = 0, lastFocus = document.activeElement;
    const back = document.createElement('div');
    back.className = 'wn-back';
    back.innerHTML = `<div class="wn" role="dialog" aria-modal="true" aria-labelledby="wn-t" aria-describedby="wn-p">
        <div class="wn-stage" aria-hidden="true"><div class="wn-bg"></div><div class="wn-grid"></div>
          <div class="wn-ring r2"><i></i></div><div class="wn-ring r1"><i></i></div><div class="wn-orb"><i class="fa-solid"></i></div>
          <div class="wn-chip c0"></div><div class="wn-chip c1"></div><div class="wn-chip c2"></div>
          ${Array.from({ length: 9 }, (_, k) => `<span class="wn-spark" style="left:${8 + (k * 37) % 84}%;top:${10 + (k * 53) % 75}%;animation-delay:${(k * .37).toFixed(2)}s"></span>`).join('')}
        </div>
        <div class="wn-top"><span class="wn-pill"><i class="fa-solid fa-wand-magic-sparkles"></i> <span class="wn-ver"></span></span>
          <button type="button" class="wn-x" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="wn-body">
          <div class="wn-bars" role="tablist" aria-label="Novedades">${S.map((s, k) => `<button type="button" class="wn-bar" role="tab" aria-label="${k + 1} de ${S.length}: ${e(s.title)}" data-go="${k}"><span></span></button>`).join('')}</div>
          <div class="wn-txt" aria-live="polite"><div class="wn-kick"></div><h3 id="wn-t"></h3><p id="wn-p"></p></div>
          <div class="wn-foot"><a class="wn-try" href="#" hidden>Probarlo <i class="fa-solid fa-arrow-right"></i></a>
            <div class="wn-nav"><button type="button" class="wn-btn wn-prev"><i class="fa-solid fa-arrow-left"></i> Anterior</button>
              <button type="button" class="wn-btn wn-next">Siguiente <i class="fa-solid fa-arrow-right"></i></button></div></div>
        </div></div>`;
    document.body.appendChild(back);
    const $ = s => back.querySelector(s), card = $('.wn');

    function go(k) {
      i = Math.max(0, Math.min(S.length - 1, k));
      const s = S[i], last = i === S.length - 1;
      card.style.setProperty('--wn', s.color);
      $('.wn-orb i').className = 'fa-solid ' + s.icon;
      ['c0', 'c1', 'c2'].forEach((c, n) => { const el = $('.wn-chip.' + c), ch = (s.chips || [])[n]; el.hidden = !ch; if (ch) el.innerHTML = `<i class="fa-solid ${e(ch[0])}"></i>${e(ch[1])}`; });
      $('.wn-ver').textContent = 'Novedades · ' + s.version;
      const nv = S.filter(x => x.version === s.version && !x.intro), pos = nv.indexOf(s) + 1;
      $('.wn-kick').textContent = s.intro ? 'Qué hay de nuevo' : `Novedad ${pos} de ${nv.length}`;
      $('#wn-t').textContent = s.title; $('#wn-p').textContent = s.text;
      const t = $('.wn-try'); t.hidden = !s.link; if (s.link) t.href = s.link;
      $('.wn-prev').disabled = i === 0;
      $('.wn-next').innerHTML = last ? '¡Listo! <i class="fa-solid fa-check"></i>' : (s.intro ? 'Empezar <i class="fa-solid fa-arrow-right"></i>' : 'Siguiente <i class="fa-solid fa-arrow-right"></i>');
      back.querySelectorAll('.wn-bar').forEach((b, n) => { b.classList.toggle('done', n <= i); b.setAttribute('aria-selected', n === i); });
      // Reinicia las animaciones de entrada
      card.classList.remove('wn-anim'); void card.offsetWidth; card.classList.add('wn-anim');
    }
    function confetti() {
      if (!moving()) return;
      const box = document.createElement('div'); box.className = 'wn-conf';
      const cols = [S[i].color, '#ffffff', '#facc15', '#60a5fa', '#f472b6'];
      box.innerHTML = Array.from({ length: 46 }, () => `<i style="left:${Math.random() * 100}%;background:${cols[Math.floor(Math.random() * cols.length)]};--fx:${(Math.random() * 160 - 80).toFixed(0)}px;--fr:${(Math.random() * 900 - 450).toFixed(0)}deg;--fy:${(380 + Math.random() * 260).toFixed(0)}px;animation-delay:${(Math.random() * .25).toFixed(2)}s"></i>`).join('');
      card.appendChild(box);
    }
    function close(done) {
      if (!open) return; open = false;
      if (markSeen) api('seen', { version: latest }).catch(() => {});
      if (done) confetti();
      document.removeEventListener('keydown', onKey, true);
      setTimeout(() => { back.classList.remove('in'); setTimeout(() => { back.remove(); if (lastFocus && lastFocus.focus) lastFocus.focus(); }, moving() ? 380 : 0); }, done && moving() ? 650 : 0);
    }
    function onKey(ev) {
      if (ev.key === 'Escape') { ev.preventDefault(); close(false); }
      else if (ev.key === 'ArrowRight') { ev.preventDefault(); i < S.length - 1 ? go(i + 1) : close(true); }
      else if (ev.key === 'ArrowLeft') { ev.preventDefault(); go(i - 1); }
      else if (ev.key === 'Tab') {   // el foco no sale de la ventana
        const f = [...card.querySelectorAll('button:not([disabled]),a[href]:not([hidden])')].filter(x => x.offsetParent !== null);
        if (!f.length) return;
        if (ev.shiftKey && document.activeElement === f[0]) { ev.preventDefault(); f[f.length - 1].focus(); }
        else if (!ev.shiftKey && document.activeElement === f[f.length - 1]) { ev.preventDefault(); f[0].focus(); }
      }
    }
    back.addEventListener('click', ev => {
      if (ev.target === back) return close(false);
      const b = ev.target.closest('button,a'); if (!b) return;
      if (b.classList.contains('wn-x')) close(false);
      else if (b.classList.contains('wn-prev')) go(i - 1);
      else if (b.classList.contains('wn-next')) { i < S.length - 1 ? go(i + 1) : close(true); }
      else if (b.dataset.go) go(+b.dataset.go);
      else if (b.classList.contains('wn-try')) { if (markSeen) api('seen', { version: latest }).catch(() => {}); markSeen = false; open = false; }
    });
    // Deslizar en el teléfono
    let x0 = null;
    card.addEventListener('touchstart', ev => { x0 = ev.touches[0].clientX; }, { passive: true });
    card.addEventListener('touchend', ev => { if (x0 === null) return; const dx = ev.changedTouches[0].clientX - x0; x0 = null; if (Math.abs(dx) > 50) go(i + (dx < 0 ? 1 : -1)); }, { passive: true });
    document.addEventListener('keydown', onKey, true);
    go(0);
    requestAnimationFrame(() => requestAnimationFrame(() => back.classList.add('in')));
    setTimeout(() => $('.wn-next').focus(), 60);
  }
  function loadCssFrom() {
    if (css) return Promise.resolve(); css = true;
    return new Promise(ok => {
      const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = SCRIPT_SRC.replace(/\.js(\?.*)?$/, '.css$1');
      l.onload = l.onerror = () => ok(); document.head.appendChild(l);
      setTimeout(ok, 1500);
    });
  }

  // Sola, al entrar, si hay algo nuevo que esta persona no vio (no encima del recorrido del menú)
  function boot() {
    const d = document.getElementById('g7-whatsnew-data');
    if (d && !/[?&]g7tour/.test(location.search)) {
      let rel = []; try { rel = JSON.parse(d.textContent || '[]'); } catch (x) {}
      setTimeout(() => show(rel, true), 700);
    }
    // "Novedades" en el menú de la persona: las últimas, aunque ya las haya visto
    document.addEventListener('click', async ev => {
      const a = ev.target.closest('[data-g7-whatsnew]'); if (!a) return;
      ev.preventDefault();
      const j = await api('all');
      if (j.releases && j.releases.length) show(j.releases.slice(0, 1), true);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
  window.G7WhatsNew = { show };
})();
