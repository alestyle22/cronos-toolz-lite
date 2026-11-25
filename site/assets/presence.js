/* assets/presence.js — Mi estado y mi insignia, desde Mi perfil (perfil.php, presence_api.php). Datos en #pr-data. */
(function () {
  'use strict';
  const D = JSON.parse(document.getElementById('pr-data').textContent), btn = document.getElementById('pf-status');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const TIMES = [[15, '15 min'], [30, '30 min'], [60, '1 hora'], [120, '2 horas'], [180, '3 horas'], [480, '8 horas'], ['day', 'Hasta el final del día'], [0, 'Hasta que lo cambie']];
  let dlg = null;
  function open() {
    let key = D.key, badge = D.badge, touched = false;
    if (!dlg) { dlg = document.createElement('dialog'); dlg.className = 'prd'; document.body.append(dlg); dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); }); }
    dlg.innerHTML = `<form method="dialog"><header><h2>Tu estado</h2><button type="button" class="prd-x" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
      <div class="prd-b"><div class="prd-grid" role="radiogroup" aria-label="Estado">${Object.entries(D.list).map(([k, s]) => `<button type="button" role="radio" data-k="${k}" style="--c:${s[1]}"><span>${s[2] || '<i></i>'}</span>${esc(s[0])}</button>`).join('')}</div>
      <label>Mensaje (opcional)<input id="prd-t" maxlength="140" placeholder="Ej: vuelvo a las 15 h" value="${esc(D.text)}"></label>
      <label id="prd-ul">Hasta cuándo<select id="prd-u">${TIMES.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select><small>Después vuelve solo a Disponible.</small></label>
      <h3>Insignia de tu foto</h3><p>Se ve en tu foto cuando estás disponible. Si tenés otro estado, se muestra el del estado.</p>
      <div class="prd-bd" role="radiogroup" aria-label="Insignia"><button type="button" role="radio" data-b="" title="Sin insignia" aria-label="Sin insignia"><i class="fa-solid fa-ban"></i></button>${Object.entries(D.badges).map(([k, b]) => `<button type="button" role="radio" data-b="${k}" title="${esc(b[1])}" aria-label="${esc(b[1])}">${b[0]}</button>`).join('')}</div>
      <div class="prd-e" role="alert"></div></div><footer><button type="button" class="pf-btn ghost" data-c>Cancelar</button><button type="button" class="pf-btn pri" data-go>Guardar</button></footer></form>`;
    const $ = s => dlg.querySelector(s), sel = $('#prd-u');
    const paint = () => {
      dlg.querySelectorAll('[data-k]').forEach(b => { b.classList.toggle('on', b.dataset.k === key); b.setAttribute('aria-checked', b.dataset.k === key); });
      dlg.querySelectorAll('[data-b]').forEach(b => { b.classList.toggle('on', b.dataset.b === badge); b.setAttribute('aria-checked', b.dataset.b === badge); });
      $('#prd-ul').hidden = key === 'available';
    };
    const dflt = () => { sel.value = String(D.list[key][3]); };
    dflt(); paint();
    dlg.querySelector('.prd-grid').addEventListener('click', e => { const b = e.target.closest('[data-k]'); if (!b) return; if (b.dataset.k !== key) $('#prd-t').value = ''; key = b.dataset.k; touched = true; dflt(); paint(); });
    dlg.querySelector('.prd-bd').addEventListener('click', e => { const b = e.target.closest('[data-b]'); if (b) { badge = b.dataset.b; paint(); } });
    sel.addEventListener('change', () => { touched = true; });
    $('.prd-x').onclick = $('[data-c]').onclick = () => dlg.close();
    $('form').addEventListener('submit', e => { e.preventDefault(); $('[data-go]').click(); });
    $('[data-go]').onclick = async e => {
      const text = $('#prd-t').value.trim(), body = { badge };
      if (touched || text !== D.text) {
        Object.assign(body, { key, text });
        // Si solo cambió el mensaje, se respeta la hora que ya tenía
        if (!touched && D.until_min !== null) body.min = Math.max(1, Math.round(D.until_min - (Date.now() - T0) / 60000));
        else if (sel.value === 'day') { const n = new Date(), f = new Date(n); f.setHours(23, 59, 0, 0); body.min = Math.max(1, Math.round((f - n) / 60000)); }
        else body.min = touched ? +sel.value : 0;
      }
      e.target.disabled = true;
      try {
        const r = await fetch('/toolz/presence_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Presence': '1' }, body: JSON.stringify(body) });
        const j = await r.json(); if (!j.ok) throw new Error(j.error || 'No se pudo guardar');
        D.badge = badge;
        if (j.status) {
          D.key = j.status.key; D.text = j.status.text; D.until_min = body.min ? body.min : null; T0 = Date.now();
          const s = D.list[D.key]; btn.style.setProperty('--c', s[1]); btn.querySelector('span').textContent = s[0];
          document.querySelector('.pf-hero-av .pf-dot')?.style.setProperty('--c', s[1]);
          let f = document.querySelector('.pf-meta .pf-focus');
          if (D.text && !f) { f = document.createElement('span'); f.className = 'pf-focus'; document.querySelector('.pf-meta').append(f); }
          if (f) { if (D.text) { f.innerHTML = '<i class="fa-solid fa-crosshairs"></i> '; f.append(D.text); } else f.remove(); }
        }
        if (window.G7Frames) G7Frames.badges(j.badges);
        dlg.close();
      } catch (err) { $('.prd-e').textContent = err.message; e.target.disabled = false; }
    };
    dlg.showModal();
  }
  let T0 = Date.now();
  btn.addEventListener('click', open);
  if (location.hash === '#estado') open();
})();
