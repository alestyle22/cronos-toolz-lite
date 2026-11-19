/* assets/user-frame.js — Mi configuración › Mi perfil › "Marco de la foto" (avatar_frame_lib.php, g7-frames.js).
 * Cada cambio se ve enseguida en tu foto (acá y en la barra) y se guarda solo, medio segundo después. */
(function () {
  'use strict';
  const start = () => {
    const box = document.getElementById('cfg-frame'); if (!box || !window.G7Frames) return;
    let cur = null; try { cur = JSON.parse(box.dataset.frame || 'null'); } catch (e) {}
    let t = 0;
    G7Frames.picker(box, cur, f => {
      G7Frames.set(G7Frames.me, f);   // se ve ya, en esta página
      clearTimeout(t);
      t = setTimeout(async () => {
        try {
          const r = await fetch('/toolz/user_config.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: 'avatar_frame', value: f || {} }) });
          const d = await r.json();
          if (typeof cfgToast === 'function') cfgToast(d.ok ? (f ? 'Marco guardado ✓' : 'Sin marco ✓') : 'Error: ' + (d.error || '?'), !d.ok);
        } catch (e) { if (typeof cfgToast === 'function') cfgToast('Error de conexión', true); }
      }, 500);
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
