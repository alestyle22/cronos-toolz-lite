/* Taller dentro de Cronos (copia adaptada de L:/Kenia/taller/nav.js) · sidebar con TODAS las herramientas del estudio.
   El botón de arriba vuelve al escritorio de Cronos.
   Se carga en el <head> de cada herramienta. Si no se encuentra, la herramienta funciona igual. */
(function(){
  // En Cronos la lista viene de studio_lib.php (taller/serve.php la deja en #g7-studio-data): todas las herramientas
  // del estudio que la persona puede abrir, las del Taller y las de Cronos (WebP, JPG, SVG, Recolorear, Renombrador).
  let D = {}; try { D = JSON.parse((document.getElementById('g7-studio-data') || {}).textContent || '{}'); } catch (e) {}
  const TOOLS = (D.tools || []).map(t => ({ id: t.id, name: t.name, desc: t.desc, icon: t.icon, grad: t.grad, url: t.url }));
  // sonidos y efectos (archivo aparte, al lado de este)
  try { const me = document.currentScript && document.currentScript.src; if (me){ const j = document.createElement('script'); j.src = me.replace(/nav\.js(\?.*)?$/, 'juice.js'); j.async = true; document.head.appendChild(j); } } catch (e) {}
  const here = D.here || 'taller';
  const href = id => id === 'taller' ? (D.home || '/toolz/') : ((TOOLS.find(t => t.id === id) || {}).url || '#');
  window.TALLER = { TOOLS, here, href };

  // ---- tema compartido: el último elegido en cualquier herramienta vale para todas
  try {
    const shared = localStorage.getItem('taller-ui');
    if (shared && here !== 'taller') localStorage.setItem(here + '-ui', shared);
    const set = Storage.prototype.setItem;
    Storage.prototype.setItem = function(k, v){ set.call(this, k, v); if (this === localStorage && /^(globo|etiqueta|calco|rotulo)-ui$/.test(k)) set.call(this, 'taller-ui', v); };
  } catch (e) {}

  const css = `
  :root{--rail:66px}
  body{padding-left:var(--rail)!important;box-sizing:border-box}
  @media (min-width:901px){body{grid-template-columns:clamp(250px,22vw,320px) minmax(0,1fr) clamp(260px,24vw,340px)!important}}
  .tl-rail{position:fixed;left:0;top:0;bottom:0;width:var(--rail);z-index:60;display:flex;flex-direction:column;align-items:center;gap:8px;padding:10px 0;
    background:var(--panel,#fff);border-right:1px solid var(--line,#e6e7ec);box-sizing:border-box}
  .tl-home{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;text-decoration:none;color:#fff;margin-bottom:6px;
    background:conic-gradient(from 200deg,#FF3B6B,#FFD43B,#22C55E,#00E5FF,#7C5CFF,#FF3B6B);box-shadow:0 6px 16px rgba(124,92,255,.35);transition:transform .35s cubic-bezier(.34,1.56,.64,1)}
  .tl-home:hover{transform:rotate(-12deg) scale(1.08)}
  .tl-home svg{width:21px;height:21px}
  .tl-sep{width:28px;height:1px;background:var(--line,#e6e7ec);margin:2px 0 4px}
  .tl-a{position:relative;width:44px;height:44px;border-radius:14px;display:grid;place-items:center;color:var(--muted,#7a7a86);text-decoration:none;transition:transform .3s cubic-bezier(.34,1.56,.64,1),background .2s,color .2s}
  .tl-a svg{width:21px;height:21px;transition:transform .3s cubic-bezier(.34,1.56,.64,1)}
  .tl-a:hover{background:var(--soft,#f4f5f8);color:var(--ink,#141418)}
  .tl-a:hover svg{transform:scale(1.12) rotate(-6deg)}
  .tl-a:active{transform:scale(.9)}
  .tl-a.on{color:#fff;background:var(--g);box-shadow:0 6px 16px rgba(0,0,0,.18)}
  .tl-a.on::before{content:"";position:absolute;left:-11px;top:50%;width:4px;height:22px;margin-top:-11px;border-radius:0 4px 4px 0;background:var(--g)}
  .tl-a .tl-k{position:absolute;right:-3px;bottom:-3px;font:700 9px/1 Inter,system-ui,sans-serif;background:var(--panel,#fff);color:var(--muted,#7a7a86);border:1px solid var(--line,#e6e7ec);border-radius:6px;padding:2px 4px;opacity:0;transition:opacity .2s}
  .tl-rail:hover .tl-k{opacity:1}
  .tl-tip{position:fixed;left:calc(var(--rail) + 8px);z-index:70;pointer-events:none;background:#16151c;color:#fff;border-radius:12px;padding:8px 12px;box-shadow:0 10px 30px rgba(0,0,0,.25);
    font:13px/1.3 Inter,system-ui,sans-serif;opacity:0;transform:translateX(-6px) scale(.96);transform-origin:left center;transition:opacity .15s,transform .25s cubic-bezier(.34,1.56,.64,1);white-space:nowrap}
  .tl-tip.on{opacity:1;transform:none}
  .tl-tip b{display:block;font-weight:700}
  .tl-tip small{color:#b9b6c8;font-size:11.5px}
  .tl-fade{position:fixed;inset:0;z-index:80;background:var(--bg,#eef0f4);opacity:0;pointer-events:none;transition:opacity .16s}
  .tl-fade.on{opacity:1}
  @media (max-width:900px){
    body{padding-left:0!important;padding-bottom:64px!important}
    .tl-rail{top:auto;right:0;width:auto;height:60px;flex-direction:row;justify-content:center;border-right:0;border-top:1px solid var(--line,#e6e7ec);padding:0 10px}
    .tl-home{margin:0 6px 0 0}
    .tl-sep{width:1px;height:28px;margin:0 4px}
    .tl-a.on::before{left:50%;top:auto;bottom:-8px;width:22px;height:4px;margin:0 0 0 -11px;border-radius:4px 4px 0 0}
    .tl-tip{display:none}
  }`;

  function build(){
    const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
    const rail = document.createElement('nav'); rail.className = 'tl-rail'; rail.setAttribute('aria-label', 'Herramientas');
    rail.innerHTML = `<a class="tl-home" href="${href('taller')}" data-n="Cronos" data-d="Volver al escritorio"><i data-lucide="house"></i></a><span class="tl-sep"></span>` +
      TOOLS.map((t, i) => `<a class="tl-a${t.id === here ? ' on' : ''}" style="--g:${t.grad}" href="${href(t.id)}" data-n="${t.name}" data-d="${t.desc}"><i data-lucide="${t.icon}"></i><span class="tl-k">Alt ${i + 1}</span></a>`).join('');
    document.body.appendChild(rail);
    const tip = document.createElement('div'); tip.className = 'tl-tip'; document.body.appendChild(tip);
    const fade = document.createElement('div'); fade.className = 'tl-fade'; document.body.appendChild(fade);
    rail.querySelectorAll('a').forEach(a => {
      a.addEventListener('mouseenter', () => { const r = a.getBoundingClientRect(); tip.innerHTML = `<b>${a.dataset.n}</b><small>${a.dataset.d}</small>`; tip.style.top = (r.top + r.height / 2 - 22) + 'px'; tip.classList.add('on'); });
      a.addEventListener('mouseleave', () => tip.classList.remove('on'));
      a.addEventListener('click', e => { if (a.classList.contains('on') || e.ctrlKey || e.metaKey || e.shiftKey) return; e.preventDefault(); fade.classList.add('on'); setTimeout(() => location.href = a.href, 150); });
    });
    window.addEventListener('pageshow', () => fade.classList.remove('on'));
    const draw = () => { try { window.lucide && lucide.createIcons(); } catch (e) {} };
    if (window.lucide) draw(); else window.addEventListener('load', draw);
    window.dispatchEvent(new Event('resize'));
  }
  document.addEventListener('keydown', e => {
    if (!e.altKey || e.ctrlKey || e.metaKey) return;
    const n = parseInt(e.key, 10); if (!(n >= 0 && n <= TOOLS.length)) return;
    e.preventDefault(); const id = n === 0 ? 'taller' : TOOLS[n - 1].id; if (id !== here) location.href = href(id);
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build); else build();
})();
