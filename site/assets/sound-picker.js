/* assets/sound-picker.js — Elegir los sonidos de los avisos: Mi configuración › Notificaciones (#snd-box) y Admin › Notificaciones
   (#sound-type-selector). Los sonidos y los estilos están en g7-juice.js (G7Juice.sounds). */
(function () {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  function start() {
    const SND = window.G7Juice && G7Juice.sounds; if (!SND) return;
    // Admin: estilo por defecto del sitio
    const adm = document.getElementById('sound-type-selector');
    if (adm) {
      adm.innerHTML = Object.entries(SND.packs).map(([k, p]) => `<button type="button" class="adm-sound-btn" data-type="${k}"><i class="fa-solid ${p.icon}" style="font-size:20px;margin-bottom:5px"></i><span>${esc(p.name)}</span><span style="font-size:10px;opacity:.7">${esc(p.desc)}</span></button>`).join('');
      if (window.admSetSoundType) admSetSoundType(SND.site, adm.querySelector(`[data-type="${SND.site}"]`));
      adm.addEventListener('click', e => { const b = e.target.closest('[data-type]'); if (b && window.admSetSoundType) { admSetSoundType(b.dataset.type, b); SND.demo(b.dataset.type); } });
    }
    const box = document.getElementById('snd-box'); if (!box) return;
    let cfg = {}; try { cfg = JSON.parse(box.dataset.cfg || '{}') || {}; } catch (e) {}
    cfg = { pack: SND.packs[cfg.pack] ? cfg.pack : '', vol: Number.isFinite(+cfg.vol) && cfg.vol !== null && cfg.vol !== undefined ? +cfg.vol : 100, ev: cfg.ev && !Array.isArray(cfg.ev) ? cfg.ev : {} };
    let t = 0;
    const save = () => { SND.set(cfg); clearTimeout(t); t = setTimeout(() => window.cfgSave && cfgSave('notif_sounds', cfg), 400); };
    const groups = {}; Object.entries(SND.list).forEach(([k, s]) => (groups[s[1]] ||= []).push([k, s[0]]));
    const draw = () => {
      const pk = cfg.pack || SND.site;
      box.innerHTML = `<div class="snd-packs" role="radiogroup" aria-label="Estilo de sonidos">${Object.entries(SND.packs).map(([k, p]) => `<button type="button" role="radio" aria-checked="${k === pk}" class="${k === pk ? 'on' : ''}" data-p="${k}"><i class="fa-solid ${p.icon}"></i><b>${esc(p.name)}</b><small>${esc(p.desc)}</small></button>`).join('')}</div>
        <label class="snd-vol"><span>Volumen</span><input type="range" min="0" max="150" step="5" value="${cfg.vol}" aria-label="Volumen de los avisos"><output>${cfg.vol}%</output></label>
        <div class="snd-evs">${Object.entries(SND.events).map(([e, n]) => `<div class="snd-ev"><span>${esc(n)}</span><select data-e="${e}" aria-label="Sonido: ${esc(n)}"><option value="">Del estilo (${esc((SND.list[SND.packs[pk].map[e]] || ['Sin sonido'])[0])})</option><option value="none"${cfg.ev[e] === 'none' ? ' selected' : ''}>Sin sonido</option>${Object.entries(groups).map(([g, l]) => `<optgroup label="${esc(g)}">${l.map(([k, nm]) => `<option value="${k}"${cfg.ev[e] === k ? ' selected' : ''}>${esc(nm)}</option>`).join('')}</optgroup>`).join('')}</select><button type="button" data-play="${e}" aria-label="Escuchar: ${esc(n)}"><i class="fa-solid fa-play"></i></button></div>`).join('')}</div>`;
    };
    box.addEventListener('click', e => {
      const p = e.target.closest('[data-p]'), pl = e.target.closest('[data-play]');
      if (p) { cfg.pack = p.dataset.p; save(); draw(); SND.demo(cfg.pack); }
      else if (pl) SND.test(pl.dataset.play);
    });
    box.addEventListener('input', e => { if (e.target.type === 'range') { cfg.vol = +e.target.value; box.querySelector('output').textContent = cfg.vol + '%'; SND.set(cfg); } });
    box.addEventListener('change', e => {
      if (e.target.type === 'range') { save(); SND.test('dm'); return; }
      const ev = e.target.dataset.e; if (!ev) return;
      if (e.target.value) cfg.ev[ev] = e.target.value; else delete cfg.ev[ev];
      save(); SND.test(ev);
    });
    draw();
  }
  if (document.readyState === 'complete') start(); else addEventListener('load', start);
})();
