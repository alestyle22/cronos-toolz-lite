/* promos/proyectos.js — Proyectos del rubro (window.PF_PROJECTS): reemplaza las listas fijas de la barra, los selects y las casillas.
   Antes en línea en index.php (CSP estricta). Se carga al final del cuerpo, después de la página. */
// Proyectos del rubro (ver PF_PROJECTS arriba): reemplaza las listas fijas de la barra, los selects y las casillas
(function () {
  // Con Admin › Marcas activo, solo los proyectos que la persona puede ver (brands.js)
  const P = window.PF_PROJECTS && (typeof window.g7BrandAllows === 'function' ? window.PF_PROJECTS.filter(n => window.g7BrandAllows(n)) : window.PF_PROJECTS); if (!P) return;
  const e = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const COL = ['var(--g7-accent)', '#fb923c', '#f87171', '#e879f9', '#fbbf24', '#22d3ee', '#a78bfa', '#60a5fa', '#4ade80', '#f472b6'];
  const all = document.getElementById('p-all');
  if (all) {
    document.querySelectorAll('.sb-btn[id^="p-"]').forEach(b => { if (b.id !== 'p-all') b.remove(); });
    let at = all;
    P.forEach((n, i) => {
      const b = document.createElement('button'); b.className = 'sb-btn'; b.id = 'p-pp' + i;
      b.onclick = function () { setProject(n, this); };
      b.innerHTML = `<span style="width:8px;height:8px;border-radius:50%;background:${n === 'Otros' ? '#71717a' : COL[i % COL.length]};flex-shrink:0;display:inline-block"></span><span class="sb-label">${e(n)}</span><span class="badge" id="cnt-pp-${i}">0</span>`;
      at.after(b); at = b;
    });
  }
  const opts = P.map(n => `<option>${e(n)}</option>`).join('');
  ['f-project', 'f-paidso-project', 'e-project'].forEach(id => { const s = document.getElementById(id); if (s) s.innerHTML = opts; });
  [['mprojects[]', ' data-on-change="updateMulti"'], ['projects[]', '']].forEach(([name, extra]) => {
    const first = document.querySelector(`input[name="${name}"]`); if (!first) return;
    const box = first.closest('label').parentNode;
    box.querySelectorAll(`input[name="${name}"]`).forEach(i => i.closest('label').remove());
    box.insertAdjacentHTML('afterbegin', P.map(n => `<label><input type="checkbox" name="${name}" value="${e(n)}"${extra}> ${e(n)}</label>`).join(''));
  });
  // Tipos ocultos en Admin › Formularios (los que el rubro no usa): tampoco en la barra
  (window.PF_CONFIG?.types || []).forEach(t => { if (t.hidden) { const b = document.getElementById('t-' + t.key); if (b) b.style.display = 'none'; } });
  if (typeof updateCounters === 'function') try { updateCounters(); } catch (x) {}
})();
