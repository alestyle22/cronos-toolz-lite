/* assets/theme-boot.js — Aplica tema, tamaño de letra y color de acento antes de pintar (antes eran scripts en línea).
   Solo toca lo que viene en los data-*: data-theme, data-font, data-accent, data-rgb. */
(function () {
  var s = document.currentScript, h = document.documentElement; if (!s) return;
  var d = s.dataset;
  if (d.theme) h.className = (h.className || '').replace(/theme-[a-z]+/g, '').trim() + ' theme-' + d.theme;
  if (d.font) h.className = (h.className || '').replace(/font-[a-z]+/g, '').trim() + ' font-' + d.font;
  if (d.accent) h.style.setProperty('--g7-accent', d.accent);
  if (d.rgb) h.style.setProperty('--g7-accent-rgb', d.rgb);
})();
