/* assets/g7-modern.js — Capa moderna para las páginas que quedaron con la estética vieja (2026-11-14).
 * No conoce los nombres de clase de cada página (cada una usa su prefijo): reconoce cada pieza por cómo se ve y le
 * pone una marca (g7m-*) que g7-modern.css termina de vestir. Solo corre en las páginas de PAGES; las que ya tienen
 * su diseño nuevo (Inicio, Admin, Mi configuración, Estadísticas, Trabajo, estudio, chat) quedan afuera.
 *   título de la página  → ícono de la sección (el del menú de arriba) y letra más grande
 *   tarjetas             → bordes redondeados, profundidad; las chicas se levantan al pasar el mouse
 *   botón principal      → degradé con brillo
 *   campos, tablas, vacíos → anillo de foco, fila resaltada, ícono grande con aire */
(function () {
  'use strict';
  const PAGES = /^\/toolz\/(soporte|promos|pages\.php|planify\.php|stock\.php|imghost\.php|claude|links|encuesta|linkstats|status\.php|todos\.php|calendario|solicitudes|clientes|facturacion|onboarding_admin\.php|comunidad\.php|resto\/admin|tienda\/admin|popup-gen|portal)/;
  if (!PAGES.test(location.pathname)) return;
  const root = document.documentElement; root.classList.add('g7m');
  const done = new WeakSet();
  const TOP = '.g7-topbar, .g7-mobile-nav, #ccw, .g7tour-tip, .g7j-chip, .g7t-toast, body [class*="g7j-"], body [class*="g7t-"], .g7-um';
  const rgb = s => (s.match(/[\d.]+/g) || []).map(Number);
  const opaque = c => { const a = rgb(c); return a.length >= 3 && (a.length < 4 || a[3] > .05); };
  let accent = null;
  const accentRGB = () => { if (accent) return accent; const p = document.createElement('i'); p.style.color = 'var(--g7-accent)'; document.body.append(p); accent = rgb(getComputedStyle(p).color).slice(0, 3); p.remove(); return accent; };
  const near = (a, b) => a && b && Math.abs(a[0] - b[0]) + Math.abs(a[1] - b[1]) + Math.abs(a[2] - b[2]) < 30;

  function titleOf(){
    // el texto más grande y en negrita cerca de arriba de la página (fuera de la barra)
    let best = null, bestFs = 0;
    const cands = document.body.querySelectorAll('h1, h2, h3, [class*="title" i], [class*="logo" i], [class*="header" i] div, [class*="head" i] div, [class*="brand" i]');
    let k = 0;
    for (const el of cands){
      if (++k > 300) break;
      if (el.closest(TOP) || !el.offsetWidth || el.closest('.g7m-title')) continue;
      const r = el.getBoundingClientRect(); if (r.top > 260 + scrollY || r.top < 40) continue;
      const cs = getComputedStyle(el), fs = parseFloat(cs.fontSize), fw = parseInt(cs.fontWeight, 10) || 400, t = (el.textContent || '').trim();
      if (fs >= 17 && fw >= 600 && t.length > 1 && t.length < 50 && !el.querySelector('input, button, select, img') && el.children.length <= 3 && fs > bestFs){ best = el; bestFs = fs; }
    }
    return best;
  }
  function header(){
    const t = titleOf(); if (!t || document.querySelector('.g7m-hico')) return;
    done.add(t); t.classList.add('g7m-title');
    const act = document.querySelector('#g7-nav a.g7-link.active i, .g7-nav a.g7-link.active i');
    const ico = document.createElement('span'); ico.className = 'g7m-hico'; ico.setAttribute('aria-hidden', 'true');
    ico.innerHTML = `<i class="${act ? act.className.replace(/\bfa-solid\b/, '').trim() + ' fa-solid' : 'fa-solid fa-layer-group'}"></i>`;
    ico.querySelector('i').removeAttribute('style');
    t.prepend(ico);
  }
  function scan(scope){
    const acc = accentRGB();
    const els = (scope || document.body).querySelectorAll('div, section, article, li, aside, form, a, button, input, select, textarea, table, [class*="empty" i]');
    let n = 0;
    for (const el of els){
      if (++n > 3000) break;
      if (done.has(el)) continue;
      if (el.closest(TOP)){ done.add(el); continue; }
      // lo que todavía no se ve (pestañas ocultas, listas que cargan después) se vuelve a mirar en la próxima pasada
      if (!el.offsetWidth && !el.offsetHeight) continue;
      done.add(el);
      const tag = el.tagName;
      if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA'){
        if (!/^(checkbox|radio|range|color|file|hidden|submit|button)$/.test(el.type)) el.classList.add('g7m-in');
        continue;
      }
      if (tag === 'TABLE'){ el.classList.add('g7m-table'); continue; }
      if (/empty/i.test(typeof el.className === 'string' ? el.className : '') && el.offsetHeight > 40){ el.classList.add('g7m-empty'); continue; }
      const cs = getComputedStyle(el);
      if (tag === 'BUTTON' || (tag === 'A' && /btn|button/i.test(el.className))){
        if (near(rgb(cs.backgroundColor).slice(0, 3), acc) && opaque(cs.backgroundColor)) el.classList.add('g7m-pri');
        else el.classList.add('g7m-btn');
        continue;
      }
      // tarjeta: fondo propio + borde o sombra + esquinas, no demasiado chica
      if (el.offsetWidth < 120 || el.offsetHeight < 60) continue;
      const br = parseFloat(cs.borderTopLeftRadius) || 0, hasBorder = parseFloat(cs.borderTopWidth) > 0 && opaque(cs.borderTopColor);
      if (br >= 6 && opaque(cs.backgroundColor) && (hasBorder || cs.boxShadow !== 'none')){
        const parentBg = el.parentElement ? getComputedStyle(el.parentElement).backgroundColor : '';
        if (cs.backgroundColor === parentBg && !hasBorder) continue;
        el.classList.add(el.offsetWidth < 520 && el.offsetHeight < 520 && !el.querySelector('input, textarea, select') ? 'g7m-card-s' : 'g7m-card');
      }
    }
  }
  let t = 0;
  const run = () => { try { header(); } catch (e) { window.__g7mErr = e; } try { scan(); } catch (e) { window.__g7mErr = e; } };
  window.G7Modern = { run };
  const later = () => { clearTimeout(t); t = setTimeout(run, 350); };
  // Arranca con el HTML listo (no espera imágenes: Stock tiene cientos) y vuelve a mirar cuando se dibuja lo que falta
  const boot = () => { run(); setTimeout(run, 900); new MutationObserver(later).observe(document.body, { childList: true, subtree: true }); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
