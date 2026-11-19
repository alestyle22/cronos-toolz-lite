/* assets/tool-studio.js — Piel "estudio" para Landing Gen, Mailing Maker y placas (sin rail del Estudio).
   Uso: <script src="/toolz/assets/tool-studio.js" data-ts="landing" data-ts-name="..." data-ts-desc="..." data-ts-icon="fa-..."
        data-ts-grad="#a,#b" data-ts-head="selector donde va el encabezado" data-ts-icons="selector de íconos de tarjetas"
        data-ts-stage="selector del escenario" data-ts-busy="selector que, visible, indica que está generando"></script>
   Pone body.ts y body.ts-<herramienta>, el encabezado con ícono, colorea los íconos de las tarjetas y marca el escenario. */
(function () {
  'use strict';
  const me = document.currentScript; if (!me) return;
  const d = me.dataset, kind = d.ts || 'tool';
  const grad = (d.tsGrad || '').split(',').map(s => s.trim()).filter(Boolean);
  function boot(){
    const b = document.body; b.classList.add('ts', 'ts-' + kind);
    if (grad.length === 2) b.style.setProperty('--ts-grad', `linear-gradient(140deg,${grad[0]},${grad[1]})`);
    // encabezado
    if (d.tsHead) {
      const host = document.querySelector(d.tsHead);
      if (host && !host.querySelector('.ts-tool')) {
        const h = document.createElement('div'); h.className = 'ts-tool';
        h.innerHTML = `<span class="ts-tool-ico"><i class="fa-solid ${d.tsIcon || 'fa-wand-magic-sparkles'}"></i></span><div><b></b><small></small></div>`;
        h.querySelector('b').textContent = d.tsName || ''; h.querySelector('small').textContent = d.tsDesc || '';
        host.prepend(h);
      }
    }
    // íconos de tarjeta con color (rotan entre 6)
    const paint = () => {
      if (d.tsIcons) document.querySelectorAll(d.tsIcons).forEach((i, k) => { if (i.classList.contains('ts-ic')) return; i.classList.add('ts-ic'); if (k % 6) i.classList.add('ts-c' + (k % 6 + 1)); });
      // títulos sin ícono: punto de color (data-ts-dots)
      if (d.tsDots) document.querySelectorAll(d.tsDots).forEach((t, k) => { if (t.classList.contains('ts-dot')) return; t.classList.add('ts-dot'); if (k % 6) t.classList.add('ts-c' + (k % 6 + 1)); });
    };
    paint();
    // escenario cuadriculado con brillo mientras genera
    const stage = d.tsStage && document.querySelector(d.tsStage);
    if (stage) stage.classList.add('ts-stage-bg', 'ts-busy-glow');
    if (d.tsBusy) {
      const check = () => { const el = document.querySelector(d.tsBusy); const on = !!(el && el.offsetParent !== null && getComputedStyle(el).display !== 'none'); b.classList.toggle('ts-busy', on); };
      new MutationObserver(() => { check(); paint(); }).observe(b, { subtree: true, attributes: true, attributeFilter: ['style', 'class'], childList: true });
      check();
    } else if (d.tsIcons || d.tsDots) new MutationObserver(paint).observe(b, { subtree: true, childList: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
