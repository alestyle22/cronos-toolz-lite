/* Cronos · transición al entrar a una aplicación (va sin defer, lo antes posible, desde topbar.php).
   Si se llegó tocando un acceso rápido, g7-juice.js dejó en sessionStorage el color y el ícono del acceso:
   la página nueva arranca cubierta de ese color y se "abre" cuando ya está dibujada, como al abrir una app.
   Respeta "Animaciones" del perfil (data-motion), "reducir movimiento" y localStorage g7-motion. Doc: toolz/MOVIMIENTO.md */
(function(){
  var s = document.currentScript, pref = null, reduce = false;
  try { pref = localStorage.getItem('g7-motion'); } catch (e) {}
  try { reduce = matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
  var on = pref !== 'off' && (pref === 'on' || ((!s || s.getAttribute('data-motion') !== '0') && (!reduce || (s && s.getAttribute('data-force') === '1'))));
  // transiciones de página del navegador (Chrome): se cancelan si la persona apagó las animaciones
  var skip = function(e){ if (!on && e.viewTransition) e.viewTransition.skipTransition(); };
  window.addEventListener('pageswap', skip); window.addEventListener('pagereveal', skip);
  var data = null;
  try { data = JSON.parse(sessionStorage.getItem('g7-vt') || 'null'); sessionStorage.removeItem('g7-vt'); } catch (e) {}
  if (!on || !data || Date.now() - data.t > 5000) return;
  var root = document.documentElement, cover = document.createElement('div');
  cover.className = 'g7vt-cover'; cover.style.background = data.bg || 'var(--g7-accent)';
  if (data.icon) { var i = document.createElement('i'); i.className = data.icon; cover.appendChild(i); }
  root.appendChild(cover); root.classList.add('g7vt-wait');
  var done = false, reveal = function(){
    if (done) return; done = true;
    root.classList.remove('g7vt-wait'); root.classList.add('g7vt-enter'); cover.classList.add('out');
    try { window.dispatchEvent(new Event('g7vt:reveal')); } catch (e) {}   // g7-juice.js hace entrar los elementos en cascada
    setTimeout(function(){ cover.remove(); }, 650); setTimeout(function(){ root.classList.remove('g7vt-enter'); }, 900);
  };
  var go = function(){ requestAnimationFrame(function(){ requestAnimationFrame(reveal); }); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
  setTimeout(reveal, 1800);   // por si la página tarda: no se queda tapada
})();
